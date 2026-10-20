<?php
// Password changes and team management, checked in a throwaway install.
//   php standalone/tests/team_smoke.php
declare(strict_types=1);

// Buffer output so PHP is still willing to start sessions. Results go to STDOUT.
ob_start();

require __DIR__ . '/../autoload.php';

use LiveChat\Core\Http\Request;
use LiveChat\Standalone\{App, Installer, Session};

$ok = function (string $name, bool $cond): void {
    fwrite(STDOUT, ($cond ? 'PASS' : 'FAIL') . "  $name\n");
    if (!$cond) {
        exit(1);
    }
};

$tmp = sys_get_temp_dir() . '/livechat-team-' . bin2hex(random_bytes(4));
mkdir($tmp);
$errors = (new Installer($tmp, __DIR__ . '/../schema'))->install([
    'driver' => 'sqlite', 'admin_name' => 'Ada', 'admin_email' => 'ada@example.com',
    'admin_password' => 'correct-horse-9', 'admin_password2' => 'correct-horse-9', 'mail_driver' => 'log',
], ['base_url' => 'http://localhost', 'host' => 'localhost']);
$ok('installed', $errors === []);
$app = App::boot($tmp);

// Each "browser" is a session cookie plus the csrf token the login gave it.
// Several browsers share this one PHP process, which a real web server never does.
// PHP would otherwise keep reusing the session it used last, so say explicitly which one is meant.
$become = function (?array $who): void {
    if ($who) {
        $_COOKIE[Session::NAME] = $who['sid'];
    } else {
        unset($_COOKIE[Session::NAME]);
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_id($who['sid'] ?? '');
    }
};
$call = function (string $method, string $path, array $body = [], ?array $who = null, string $ip = '9.9.9.9') use ($app, $become) {
    $become($who);
    $headers = $who ? ['x-csrf-token' => $who['csrf']] : [];
    return $app->auth->handle(new Request($method, $path, [], $body, $headers, $ip));
};
$login = function (string $email, string $password, string $ip) use ($call): ?array {
    $r = $call('POST', '/login', ['email' => $email, 'password' => $password], null, $ip);
    return $r->status === 200 ? ['sid' => session_id(), 'csrf' => $r->data['csrf']] : null;
};
$api = function (string $method, string $path, ?array $who) use ($app, $become) {
    $become($who);
    return $app->router->handle(new Request($method, $path, [], [], $who ? ['x-csrf-token' => $who['csrf']] : [], '1.2.3.4'));
};

// ---- not logged in ----
$ok('team list needs a login', $call('GET', '/agents')->status === 401);
$ok('password change needs a login', $call('POST', '/password', ['current' => 'x', 'new' => 'y'])->status === 401);

// ---- the admin ----
$admin = $login('ada@example.com', 'correct-horse-9', '10.0.0.1');
$ok('admin logs in', $admin !== null);
$r = $call('GET', '/agents', [], $admin);
$ok('admin sees the team (just themselves so far)', $r->status === 200 && count($r->data['agents']) === 1 && $r->data['agents'][0]['is_admin'] === true);

$form = ['name' => 'Bo', 'email' => 'Bo@Example.com', 'password' => 'bo-password-1'];
$become($admin);                                           // logged in, but sending no csrf header
$ok('adding a teammate without the csrf header is refused', $app->auth->handle(new Request('POST', '/agents', [], $form, [], '9.9.9.9'))->status === 403);

$r = $call('POST', '/agents', $form, $admin);
$ok('admin adds a teammate', $r->status === 201 && $r->data['agent']['email'] === 'bo@example.com' && $r->data['agent']['is_admin'] === false);
$boId = $r->data['agent']['id'];
$ok('team now has two people', count($call('GET', '/agents', [], $admin)->data['agents']) === 2);

$ok('same email again (any case) is refused', $call('POST', '/agents', ['email' => 'BO@example.com'] + $form, $admin)->status === 409);
$ok('short password is refused', $call('POST', '/agents', ['email' => 'c@example.com', 'password' => 'short'] + $form, $admin)->status === 422);
$ok('bad email is refused', $call('POST', '/agents', ['email' => 'nope'] + $form, $admin)->status === 422);
$ok('empty name is refused', $call('POST', '/agents', ['name' => '  ', 'email' => 'd@example.com'] + $form, $admin)->status === 422);

// ---- the teammate ----
$bo = $login('bo@example.com', 'bo-password-1', '10.0.0.2');
$ok('teammate can log in', $bo !== null);
$ok('teammate can use the chat API', $api('GET', '/agent/events', $bo)->status === 200);
$ok('teammate cannot see the team list', $call('GET', '/agents', [], $bo)->status === 403);
$ok('teammate cannot add people', $call('POST', '/agents', ['email' => 'e@example.com'] + $form, $bo)->status === 403);
$ok('teammate cannot remove people', $call('POST', "/agents/1/delete", [], $bo)->status === 403);

// ---- changing your own password ----
$ok('wrong current password is refused', $call('POST', '/password', ['current' => 'not-it-at-all', 'new' => 'brand-new-pass-1'], $bo)->status === 422);
$ok('too-short new password is refused', $call('POST', '/password', ['current' => 'bo-password-1', 'new' => 'short'], $bo)->status === 422);
$ok('right current password changes it', $call('POST', '/password', ['current' => 'bo-password-1', 'new' => 'brand-new-pass-1'], $bo)->status === 200);
$ok('the old password stops working', $login('bo@example.com', 'bo-password-1', '10.0.0.3') === null);
$bo = $login('bo@example.com', 'brand-new-pass-1', '10.0.0.4');
$ok('the new password works', $bo !== null);

// ---- admin sets a password for them ----
$ok('admin can set a teammate\'s password', $call('POST', "/agents/$boId/password", ['password' => 'reset-by-admin-1'], $admin)->status === 200);
$ok('admin cannot set a too-short one', $call('POST', "/agents/$boId/password", ['password' => 'short'], $admin)->status === 422);
$bo = $login('bo@example.com', 'reset-by-admin-1', '10.0.0.5');
$ok('teammate logs in with the admin-set password', $bo !== null);

// ---- removing people ----
$ok('admin cannot remove themselves', $call('POST', '/agents/1/delete', [], $admin)->status === 422);
$ok('removing someone who does not exist is a 404', $call('POST', '/agents/999/delete', [], $admin)->status === 404);
$ok('admin removes the teammate', $call('POST', "/agents/$boId/delete", [], $admin)->status === 200);
$ok('team is back to one person', count($call('GET', '/agents', [], $admin)->data['agents']) === 1);
$ok('their still-open session stops working at once', $api('GET', '/agent/events', $bo)->status === 401);
$ok('they cannot log in any more', $login('bo@example.com', 'reset-by-admin-1', '10.0.0.6') === null);
$ok('their email can be used again', $call('POST', '/agents', $form, $admin)->status === 201);

// ---- guessing the current password ----
$last = null;
for ($i = 0; $i < 7; $i++) {
    $last = $call('POST', '/password', ['current' => "guess-$i-xxxxxxxx", 'new' => 'whatever-long-1'], $admin);
}
$ok('guessing the current password gets rate limited', $last->status === 429);

// ---- clean up ----
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

fwrite(STDOUT, "\nAll good.\n");
