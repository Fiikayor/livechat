<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Contracts\{RateLimiterInterface, StorageInterface};
use LiveChat\Core\Domain\Agent;
use LiveChat\Core\Http\{Request, Response};
use LiveChat\Core\Support\ChatException;

// Login, logout and "who am I" for the dashboard.
//   POST /login   { email, password }  ->  { agent, csrf }
//   POST /logout
//   GET  /me                           ->  { agent, csrf }
final class AuthEndpoint
{
    // A real hash of a random string. When the email doesn't exist we still
    // check the password against this, so "unknown email" and "wrong password"
    // take the same time and an attacker can't tell which emails are registered.
    private const DUMMY_HASH = '$2y$10$3peVXqDMkAzjftEbC5o9OOzkyInZbh0NYdR7M15Et4gCX44enWV/W';

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

        throw new ChatException('not_found', 'Unknown endpoint.', 404);
    }

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

    private static function present(Agent $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'is_admin' => $a->isAdmin];
    }
}
