<?php
// Same chat story against a real MySQL/MariaDB server (XAMPP's works).
// Uses a throwaway database, so point it at an empty one:
//   LIVECHAT_MYSQL_DSN="mysql:host=127.0.0.1;dbname=livechat_test;charset=utf8mb4" \
//   LIVECHAT_MYSQL_USER=root LIVECHAT_MYSQL_PASS= php standalone/tests/mysql_smoke.php
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

$dsn = getenv('LIVECHAT_MYSQL_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set LIVECHAT_MYSQL_DSN (see top of this file).\n");
    exit(2);
}

$pdo = new PDO($dsn, getenv('LIVECHAT_MYSQL_USER') ?: 'root', getenv('LIVECHAT_MYSQL_PASS') ?: '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// start from a clean slate so the test can be re-run
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['messages', 'conversations', 'rate_limits', 'agents'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// the schema file has several statements, so run them one by one
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../schema/mysql.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

$pdo->exec("INSERT INTO agents (name, email, password_hash) VALUES ('Ada', 'ada@x.test', 'not-a-real-hash')");

livechat_run_story(new PdoStorage($pdo), new PdoRateLimiter($pdo));
