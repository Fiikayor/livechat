#!/usr/bin/env php
<?php
declare(strict_types=1);

// Builds the folder you upload to a website.
//
//   php bin/build-release.php                          -> dist/livechat/
//   php bin/build-release.php --version=0.1.0 --zip    -> also dist/livechat-0.1.0.zip
//   php bin/build-release.php --out=/some/folder
//
// Only the files listed below are copied. It never says "copy this whole
// folder", so a database, config file or test from your own machine can't
// end up in a release by accident.

$root = dirname(__DIR__);
$opts = getopt('', ['version:', 'out:', 'zip']);

$version = (string) ($opts['version'] ?? 'dev');
if (!preg_match('/^[0-9A-Za-z._-]+$/', $version)) {
    fail("The version can only contain letters, digits, dots, dashes and underscores (got: $version).");
}
$outDir = rtrim((string) ($opts['out'] ?? $root . '/dist'), '/\\');
$target = $outDir . '/livechat';

// source in the repository => place inside the upload folder
$files = [
    'standalone/install.php'   => 'install.php',
    'standalone/api.php'       => 'api.php',
    'standalone/auth.php'      => 'auth.php',
    'standalone/autoload.php'  => 'autoload.php',
    'widget/livechat.js'       => 'widget/livechat.js',
    'dashboard/index.html'     => 'dashboard/index.html',
    'dashboard/settings.html'  => 'dashboard/settings.html',
    'LICENSE'                  => 'LICENSE',
];

// whole folders, but only files with these extensions
$folders = [
    'standalone/src'    => ['src', ['php']],
    'standalone/schema' => ['schema', ['sql']],
    'core/src'          => ['core/src', ['php']],     // the framework-free core travels inside the folder
];

// ---------- build ----------

removeTree($target);
mkdir($target, 0755, true);

foreach ($files as $from => $to) {
    put("$root/$from", "$target/$to");
}

foreach ($folders as $from => [$to, $extensions]) {
    $list = walk("$root/$from", $extensions);
    if (!$list) {
        fail("Nothing found in $from.");
    }
    foreach ($list as $path) {
        put($path, "$target/$to/" . relative("$root/$from", $path));
    }
}

// The install guide. {{VERSION}} is filled in.
$guide = @file_get_contents("$root/docs/INSTALL.txt");
if ($guide === false) {
    fail('Missing docs/INSTALL.txt');
}
file_put_contents("$target/README.txt", str_replace('{{VERSION}}', $version, $guide));
file_put_contents("$target/VERSION", $version . "\n");

// Folders nobody should open in a browser. Apache obeys this file; nginx
// ignores it (the install guide explains the nginx rule).
$deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
foreach (['src', 'core', 'schema', 'storage'] as $folder) {
    @mkdir("$target/$folder", 0755, true);
    file_put_contents("$target/$folder/.htaccess", $deny);
}

// storage/ ships ready-made so the only thing left to do is make it writable
file_put_contents("$target/storage/index.html", '');

// ---------- zip ----------

$zipPath = null;
if (isset($opts['zip'])) {
    if (!class_exists('ZipArchive')) {
        fail("--zip needs PHP's zip extension, which is not installed here. Zip the livechat folder yourself instead.");
    }
    $zipPath = "$outDir/livechat-$version.zip";
    @unlink($zipPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fail("Could not create $zipPath");
    }
    foreach (walk($target, null) as $path) {
        $zip->addFile($path, 'livechat/' . relative($target, $path));
    }
    $zip->close();
}

// ---------- report ----------

$all = walk($target, null);
$bytes = array_sum(array_map('filesize', $all));
echo "Built LiveChat $version\n";
echo "  folder: $target\n";
echo '  files:  ' . count($all) . ' (' . round($bytes / 1024) . " KB)\n";
if ($zipPath) {
    echo "  zip:    $zipPath (" . round(filesize($zipPath) / 1024) . " KB)\n";
}

// ---------- helpers ----------

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

function put(string $from, string $to): void
{
    if (!is_file($from)) {
        fail("Missing source file: $from");
    }
    $dir = dirname($to);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        fail("Could not create $dir");
    }
    if (!copy($from, $to)) {
        fail("Could not copy $from");
    }
}

/** @return string[] sorted, so the result is the same every time. $extensions = null means every file. */
function walk(string $dir, ?array $extensions): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && ($extensions === null || in_array(strtolower($file->getExtension()), $extensions, true))) {
            $found[] = $file->getPathname();
        }
    }
    sort($found);
    return $found;
}

function relative(string $base, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($base) + 1));
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
