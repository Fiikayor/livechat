<?php
// Same chat story, but against a real (in-memory) SQLite database
// using PdoStorage and PdoRateLimiter:
//   php standalone/tests/pdo_smoke.php
declare(strict_types=1);

spl_autoload_register(function ($class) {
    $map = [
        'LiveChat\\Core\\'       => __DIR__ . '/../../core/src/',
        'LiveChat\\Standalone\\' => __DIR__ . '/../src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});
require __DIR__ . '/../../core/tests/story.php';

use LiveChat\Standalone\{PdoRateLimiter, PdoStorage};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');                       // SQLite ignores foreign keys unless asked
$pdo->exec(file_get_contents(__DIR__ . '/../schema/sqlite.sql'));

// the story expects agent #1 to exist
$pdo->exec("INSERT INTO agents (name, email, password_hash) VALUES ('Ada', 'ada@x.test', 'not-a-real-hash')");

livechat_run_story(new PdoStorage($pdo), new PdoRateLimiter($pdo));