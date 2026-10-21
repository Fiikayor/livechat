<?php
// Builds a release into a temporary folder and checks that it stands on its own:
// right files in, private files out, and that it installs and chats from inside
// that folder without needing anything from the repository.
//   php standalone/tests/release_smoke.php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$ok = function (string $name, bool $cond, string $detail = ''): void {
    fwrite(STDOUT, ($cond ? 'PASS' : 'FAIL') . "  $name" . ($cond || $detail === '' ? '' : "  ($detail)") . "\n");
    if (!$cond) {
        exit(1);
    }
};

$tmp = sys_get_temp_dir() . '/livechat-release-' . bin2hex(random_bytes(4));
mkdir($tmp);

// ---- build ----
$zipPossible = class_exists('ZipArchive');
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/build-release.php')
    . ' --version=9.9.9 --out=' . escapeshellarg($tmp) . ($zipPossible ? ' --zip' : '') . ' 2>&1',
    $output,
    $code
);
$ok('the build script succeeds', $code === 0, implode(' | ', $output));
$dist = $tmp . '/livechat';

$list = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $list[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dist) + 1));
    }
}

// ---- what must be in it ----
$required = [
    'install.php', 'api.php', 'auth.php', 'autoload.php', 'README.txt', 'LICENSE', 'VERSION',
    'widget/livechat.js', 'dashboard/index.html', 'dashboard/settings.html',
    'schema/sqlite.sql', 'schema/mysql.sql', 'src/PdoStorage.php', 'src/Installer.php',
    'core/src/Http/ApiRouter.php', 'storage/.htaccess', 'storage/index.html',
];
$missing = array_values(array_diff($required, $list));
$ok('every file a site owner needs is there', !$missing, implode(', ', $missing));

foreach (['src', 'core', 'schema', 'storage'] as $folder) {
    $ok("$folder/ is closed to web visitors", is_file("$dist/$folder/.htaccess"));
}

$guide = (string) file_get_contents("$dist/README.txt");
$ok('the install guide shows the version', str_contains($guide, 'LiveChat 9.9.9') && !str_contains($guide, '{{'));

// ---- what must NOT be in it ----
$leaks = array_values(array_filter($list, fn ($f) =>
    preg_match('#(^|/)(tests?|bin|docs|\.git|node_modules|dist)(/|$)#', $f)
    || preg_match('#\.(sqlite3?|log|tmp)$#', $f)
    || $f === 'storage/config.php'
));
$ok('no tests, scripts, databases or config files slipped in', !$leaks, implode(', ', $leaks));

// ---- every PHP file is valid ----
$broken = [];
foreach ($list as $f) {
    if (str_ends_with($f, '.php')) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$dist/$f") . ' 2>&1', $o, $c);
        if ($c !== 0) {
            $broken[] = $f;
        }
    }
}
$ok('every PHP file in the release is valid', !$broken, implode(', ', $broken));

// ---- it works from inside that folder ----
// Only the release's own autoloader is loaded here, so if the core were missing
// from the folder (or found in the repository instead) this would fail.
require $dist . '/autoload.php';

use LiveChat\Core\Http\{ApiRouter, Request};
use LiveChat\Standalone\{App, Installer};

$errors = (new Installer($dist, $dist . '/schema'))->install([
    'driver' => 'sqlite', 'admin_name' => 'Ada', 'admin_email' => 'ada@example.com',
    'admin_password' => 'correct-horse-9', 'admin_password2' => 'correct-horse-9', 'mail_driver' => 'log',
], ['base_url' => 'http://localhost/livechat', 'host' => 'localhost']);
$ok('the built folder installs itself', $errors === [], implode(' | ', $errors));

$app = App::boot($dist);
$loadedFrom = (string) (new ReflectionClass(ApiRouter::class))->getFileName();
$ok('the core is loaded from inside the release, not from the repository', str_starts_with($loadedFrom, (string) realpath($dist)));

$call = fn (string $m, string $p, array $body = [], array $h = []) => $app->router->handle(new Request($m, $p, [], $body, $h, '1.2.3.4'));
$ok('the API answers', $call('GET', '/config')->status === 200);
$r = $call('POST', '/conversations', ['name' => 'Sam']);
$ok('a visitor can start a chat', $r->status === 201);
$ok('and send a message', $call('POST', '/messages', ['body' => 'Hello'], ['x-chat-token' => $r->data['visitor_token']])->status === 201);

// ---- the pieces find each other after the move ----
// These are the connections that break quietly when folders are rearranged.
$widget = (string) file_get_contents("$dist/widget/livechat.js");
$index = (string) file_get_contents("$dist/dashboard/index.html");
$settings = (string) file_get_contents("$dist/dashboard/settings.html");
$ok('the widget finds api.php next to its folder when data-api is missing', str_contains($widget, "new URL('../api.php', script.src)"));
$ok('the dashboard looks for api.php one folder up', str_contains($index, "'../api.php'"));
$ok('the settings page points the embed code at widget/livechat.js', str_contains($settings, "'../widget/livechat.js'"));

// ---- the zip ----
if ($zipPossible) {
    $zip = new ZipArchive();
    $ok('the zip opens', $zip->open("$tmp/livechat-9.9.9.zip") === true);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    $ok('everything in the zip sits in one "livechat" folder', $names && count(array_filter($names, fn ($n) => !str_starts_with($n, 'livechat/'))) === 0);
    $ok('the zip contains install.php and the widget', in_array('livechat/install.php', $names, true) && in_array('livechat/widget/livechat.js', $names, true));
    $ok('the zip has the same files as the folder', count($names) === count($list));
} else {
    fwrite(STDOUT, "SKIP  zip checks (this PHP has no zip extension)\n");
}

// ---- clean up ----
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

fwrite(STDOUT, "\nAll good.\n");
