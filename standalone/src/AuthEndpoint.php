<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Contracts\{RateLimiterInterface, StorageInterface};
use LiveChat\Core\Domain\Agent;
use LiveChat\Core\Http\{Request, Response};
use LiveChat\Core\Support\ChatException;

// Who you are, and who else is allowed to log in.
//
//   POST /login    { email, password }         -> { agent, csrf }
//   POST /logout
//   GET  /me                                   -> { agent, csrf }
//   POST /password { current, new }            change your own password
//
// Admin only (the person who ran the installer):
//   GET  /agents                               -> { agents: [...] }
//   POST /agents   { name, email, password }   add a teammate
//   POST /agents/{id}/password { password }    give a teammate a new password
//   POST /agents/{id}/delete                   remove a teammate
final class AuthEndpoint
{
    // A real hash of a random string. When the email doesn't exist we still
    // check the password against this, so "unknown email" and "wrong password"
    // take the same time and an attacker can't tell which emails are registered.
    private const DUMMY_HASH = '$2y$10$3peVXqDMkAzjftEbC5o9OOzkyInZbh0NYdR7M15Et4gCX44enWV/W';

    private const MIN_PASSWORD = 10;

    public function __construct(
        private AgentRepository $agents,
        private StorageInterface $storage,
        private RateLimiterInterface $limiter,
    ) {}

    public function handle(Request $r): Response
    {
        try {
            return $this->dispatch($r);
        } catch (ChatException $e) {
            return new Response($e->httpStatus, [
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
            ]);
        }
    }

    private function dispatch(Request $r): Response
    {
        $path = rtrim($r->path, '/') ?: '/';

        if ($r->method === 'POST' && $path === '/login') {
            return $this->login($r);
        }

        if ($r->method === 'POST' && $path === '/logout') {
            Session::destroy();
            return new Response(200, ['ok' => true]);
        }

        if ($r->method === 'GET' && $path === '/me') {
            $session = Session::read();
            $agent = is_int($session['agent_id'] ?? null) ? $this->storage->findAgent($session['agent_id']) : null;
            if ($agent === null) {
                throw new ChatException('unauthenticated', 'Login required.', 401);
            }
            return new Response(200, ['agent' => self::present($agent), 'csrf' => (string) ($session['csrf'] ?? '')]);
        }

        if ($r->method === 'POST' && $path === '/password') {
            return $this->changeOwnPassword($r);
        }

        if ($path === '/agents' || str_starts_with($path, '/agents/')) {
            return $this->team($r, $path);
        }

        throw new ChatException('not_found', 'Unknown endpoint.', 404);
    }

    // ---------- login ----------

    private function login(Request $r): Response
    {
        $email = strtolower(trim((string) ($r->body['email'] ?? '')));
        $password = (string) ($r->body['password'] ?? '');

        // Slow down password guessing: per IP, and per email so that a botnet
        // can't hammer one account from many addresses.
        if (
            !$this->limiter->hit("login:ip:{$r->ip}", 10, 600)
            || !$this->limiter->hit('login:email:' . hash('sha256', $email), 5, 600)
        ) {
            throw new ChatException('rate_limited', 'Too many login attempts. Try again in a few minutes.', 429);
        }

        // bcrypt only looks at the first 72 bytes, and hashing a megabyte of
        // input would just be a free way to burn our CPU
        $row = ($email !== '' && strlen($password) <= 1000) ? $this->agents->findByEmail($email) : null;
        $matches = password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH);

        if ($row === null || !$matches) {
            throw new ChatException('invalid_credentials', 'Wrong email or password.', 401);
        }

        $id = (int) $row['id'];
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $this->agents->updateHash($id, $password);
        }

        $agent = $this->storage->findAgent($id)
            ?? throw new ChatException('invalid_credentials', 'Wrong email or password.', 401);

        // new session id on login, so a session id planted before login is useless
        Session::open();
        session_regenerate_id(true);
        $csrf = bin2hex(random_bytes(32));
        $_SESSION = ['agent_id' => $id, 'csrf' => $csrf];
        session_write_close();

        return new Response(200, ['agent' => self::present($agent), 'csrf' => $csrf]);
    }

    // ---------- your own password ----------

    private function changeOwnPassword(Request $r): Response
    {
        $agent = $this->requireAgent($r);

        // they're logged in already, but a stolen session shouldn't be able
        // to guess the current password at full speed
        if (!$this->limiter->hit('password:' . $agent->id, 5, 600)) {
            throw new ChatException('rate_limited', 'Too many attempts. Try again in a few minutes.', 429);
        }

        $current = (string) ($r->body['current'] ?? '');
        $new = (string) ($r->body['new'] ?? '');

        $row = strlen($current) <= 1000 ? $this->agents->findByEmail($agent->email) : null;
        if ($row === null || !password_verify($current, $row['password_hash'])) {
            throw new ChatException('wrong_password', 'Your current password is not right.', 422);
        }

        $this->checkPassword($new);
        $this->agents->updateHash($agent->id, $new);

        return new Response(200, ['ok' => true]);
    }

    // ---------- the team (admin only) ----------

    private function team(Request $r, string $path): Response
    {
        $admin = $this->requireAgent($r);
        if (!$admin->isAdmin) {
            throw new ChatException('forbidden', 'Only the admin can manage the team.', 403);
        }

        if ($r->method === 'GET' && $path === '/agents') {
            return new Response(200, ['agents' => array_map(fn (Agent $a) => self::present($a), $this->storage->listAgents())]);
        }

        if ($r->method === 'POST' && $path === '/agents') {
            return $this->addTeammate($r);
        }

        if ($r->method === 'POST' && preg_match('#^/agents/(\d+)/(password|delete)$#', $path, $m)) {
            $id = (int) $m[1];
            $target = $this->storage->findAgent($id)
                ?? throw new ChatException('not_found', 'No such teammate.', 404);

            if ($m[2] === 'password') {
                $new = (string) ($r->body['password'] ?? '');
                $this->checkPassword($new);
                $this->agents->updateHash($id, $new);
                return new Response(200, ['ok' => true]);
            }

            if ($id === $admin->id) {
                throw new ChatException('cannot_remove_self', 'You can\'t remove yourself.', 422);
            }
            if ($target->isAdmin) {
                throw new ChatException('admin_protected', 'Admins can\'t be removed here.', 422);
            }
            $this->agents->delete($id);
            return new Response(200, ['ok' => true]);
        }

        throw new ChatException('not_found', 'Unknown endpoint.', 404);
    }

    private function addTeammate(Request $r): Response
    {
        $name = trim((string) ($r->body['name'] ?? ''));
        $email = strtolower(trim((string) ($r->body['email'] ?? '')));
        $password = (string) ($r->body['password'] ?? '');

        if ($name === '' || strlen($name) > 100) {
            throw new ChatException('invalid_name', 'Enter a name (up to 100 characters).', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new ChatException('invalid_email', 'Enter a valid email address.', 422);
        }
        $this->checkPassword($password);

        if ($this->agents->findByEmail($email) !== null) {
            throw new ChatException('email_taken', 'Someone with that email already has an account.', 409);
        }

        try {
            $id = $this->agents->create($name, $email, $password, false);
        } catch (\PDOException) {
            // two admins adding the same email at the same moment: the database's UNIQUE rule caught it
            throw new ChatException('email_taken', 'Someone with that email already has an account.', 409);
        }

        $agent = $this->storage->findAgent($id)
            ?? throw new ChatException('server_error', 'The account was created but could not be read back.', 500);

        return new Response(201, ['agent' => self::present($agent)]);
    }

    // ---------- helpers ----------

    // Same check the rest of the API uses, so this also demands the CSRF
    // header on everything that changes data.
    private function requireAgent(Request $r): Agent
    {
        return (new SessionAgentAuthenticator($this->storage))->authenticate($r)
            ?? throw new ChatException('unauthenticated', 'Login required.', 401);
    }

    private function checkPassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new ChatException('weak_password', 'The password needs at least ' . self::MIN_PASSWORD . ' characters.', 422);
        }
        if (strlen($password) > 200) {
            throw new ChatException('weak_password', 'That password is too long (200 characters max).', 422);
        }
    }

    private static function present(Agent $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'is_admin' => $a->isAdmin];
    }
}
