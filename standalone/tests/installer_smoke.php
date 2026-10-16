<?php
// Installs LiveChat into a temporary folder, then checks the pieces the
// installer sets up: validation, config, chat + email, login, CSRF, lockout.
//   php standalone/tests/installer_smoke.php
declare(strict_types=1);

// Buffer output so PHP is still willing to start sessions (it refuses once
// anything has been printed). Results go straight to STDOUT instead.
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

$tmp = sys_get_temp_dir() . '/livechat-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$installer = new Installer($tmp, __DIR__ . '/../schema');
$env = ['base_url' => 'http://localhost/livechat', 'host' => 'localhost'];

$form = [
    'driver' => 'sqlite', 'admin_name' => 'Ada', 'admin_email' => 'Ada@Example.com',
    'admin_password' => 'correct-horse-9', 'admin_password2' => 'correct-horse-9',
    'site_name' => 'Demo Shop', 'mail_driver' => 'log',
];

// ---- installer ----
$ok('fresh folder is not installed', !$installer->isInstalled());
$ok('short password is rejected', count($installer->install(['admin_password' => 'short'] + $form, $env)) > 0);
$ok('mismatched passwords are rejected', count($installer->install(['admin_password2' => 'different-pass-1'] + $form, $env)) > 0);
$ok('bad email is rejected', count($installer->install(['admin_email' => 'not-an-email'] + $form, $env)) > 0);
$ok('mysql without a database name is rejected', count($installer->install(['driver' => 'mysql'] + $form, $env)) > 0);
$ok('nothing was written by the failed attempts', !$installer->isInstalled());

$ok('install succeeds', $installer->install($form, $env) === []);
$ok('config.php exists', $installer->isInstalled());
$ok('second install is refused', $installer->install($form, $env) !== []);
$ok('storage is protected from the web', is_file("$tmp/storage/.htaccess"));
$ok('sqlite file has a random name', count(glob("$tmp/storage/livechat-*.sqlite")) === 1);

// ---- chat + email ----
$app = App::boot($tmp);
$call = fn (string $m, string $p, array $body = [], array $q = [], array $h = [])
    => $app->router->handle(new Request($m, $p, $q, $body, $h, '1.2.3.4'));

$r = $call('POST', '/conversations', ['name' => 'Sam', 'email' => 'sam@x.com']);
$tok = $r->data['visitor_token'];
$call('POST', '/messages', ['body' => 'Hi, do you ship to Lagos?', 'client_id' => 'c1'], [], ['x-chat-token' => $tok]);
$call('POST', '/messages', ['body' => 'hello??', 'client_id' => 'c2'], [], ['x-chat-token' => $tok]);
$app->notifier->flush();

$log = (string) @file_get_contents("$tmp/storage/mail.log");
$ok('owner got an email with the message', str_contains($log, 'Hi, do you ship to Lagos?') && str_contains($log, 'To: ada@example.com'));
$ok('two messages produced only one email', substr_count($log, "\nTo: ") + (str_starts_with($log, 'To: ') ? 1 : 0) === 1);

// ---- login, csrf, logout ----
$auth = fn (string $m, string $p, array $body = []) => $app->auth->handle(new Request($m, $p, [], $body, [], '9.9.9.9'));

$ok('wrong password -> 401', $auth('POST', '/login', ['email' => 'ada@example.com', 'password' => 'nope-nope-nope'])->status === 401);
$ok('unknown email gives the same answer', $auth('POST', '/login', ['email' => 'ghost@example.com', 'password' => 'nope-nope-nope'])->data['error']['code'] === 'invalid_credentials');

$r = $auth('POST', '/login', ['email' => 'ADA@example.com', 'password' => 'correct-horse-9']);
$ok('right password logs in (email case ignored)', $r->status === 200 && $r->data['agent']['is_admin'] === true);
$csrf = $r->data['csrf'];
$_COOKIE[Session::NAME] = session_id();          // what the browser would send back

$ok('/me knows who is logged in', $auth('GET', '/me')->data['agent']['email'] === 'ada@example.com');

$withCookie = fn (string $m, string $p, array $body = [], array $h = [])
    => $app->router->handle(new Request($m, $p, [], $body, $h, '1.2.3.4'));

$ok('agent GET works with just the cookie', $withCookie('GET', '/agent/events')->status === 200);
$r = $withCookie('POST', '/agent/conversations/1/messages', ['body' => 'Yes we do!']);
$ok('agent POST without csrf header -> 403', $r->status === 403 && $r->data['error']['code'] === 'csrf_failed');
$r = $withCookie('POST', '/agent/conversations/1/messages', ['body' => 'Yes we do!'], ['x-csrf-token' => 'wrong']);
$ok('agent POST with wrong csrf -> 403', $r->status === 403);
$r = $withCookie('POST', '/agent/conversations/1/messages', ['body' => 'Yes we do!'], ['x-csrf-token' => $csrf]);
$ok('agent POST with csrf works', $r->status === 201);

$auth('POST', '/logout');
unset($_COOKIE[Session::NAME]);
$ok('after logout /me -> 401', $auth('GET', '/me')->status === 401);
$ok('after logout agent endpoints -> 401', $withCookie('GET', '/agent/events')->status === 401);

// ---- brute force ----
$last = null;
for ($i = 0; $i < 8; $i++) {
    $last = $auth('POST', '/login', ['email' => 'ada@example.com', 'password' => "guess-number-$i"]);
}
$ok('repeated wrong logins get rate limited -> 429', $last->status === 429);

// ---- clean up the temp folder ----
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

fwrite(STDOUT, "\nAll good.\n");
