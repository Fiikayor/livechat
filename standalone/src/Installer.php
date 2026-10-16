<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use PDO;
use PDOException;

// Everything the installer page does, kept out of the HTML so it can be tested.
final class Installer
{
    // $dir is the standalone folder (storage/ goes inside it),
    // $schemaDir is where sqlite.sql and mysql.sql live.
    public function __construct(private string $dir, private string $schemaDir) {}

    public function isInstalled(): bool
    {
        return is_file(App::configPath($this->dir));
    }

    /** @return array<string, array{ok: bool, text: string}> */
    public function requirements(): array
    {
        $storage = $this->dir . '/storage';

        return [
            'php'    => ['ok' => PHP_VERSION_ID >= 80100, 'text' => 'PHP 8.1 or newer (this server runs ' . PHP_VERSION . ')'],
            'json'   => ['ok' => extension_loaded('json'), 'text' => 'JSON support'],
            'sqlite' => ['ok' => extension_loaded('pdo_sqlite'), 'text' => 'SQLite support (pdo_sqlite)'],
            'mysql'  => ['ok' => extension_loaded('pdo_mysql'), 'text' => 'MySQL support (pdo_mysql)'],
            'write'  => [
                'ok'   => is_dir($storage) ? is_writable($storage) : is_writable($this->dir),
                'text' => 'The livechat folder is writable',
            ],
        ];
    }

    // Enough to install: new PHP, JSON, somewhere to write, and at least one database driver.
    public function canInstall(): bool
    {
        $r = $this->requirements();
        return $r['php']['ok'] && $r['json']['ok'] && $r['write']['ok'] && ($r['sqlite']['ok'] || $r['mysql']['ok']);
    }

    /**
     * @param array<string, mixed>  $in  the submitted form
     * @param array<string, string> $env base_url and host of this server
     * @return string[] problems to show the user. Empty means it worked.
     */
    public function install(array $in, array $env): array
    {
        if ($this->isInstalled()) {
            return ['LiveChat is already installed.'];
        }

        $errors = $this->validate($in, $clean);
        if ($errors) {
            return $errors;
        }
        if (!$this->canInstall()) {
            return ['This server is missing something LiveChat needs. See the checklist at the top of the page.'];
        }

        $storageDir = $this->dir . '/storage';
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0750, true)) {
            return ['Could not create the storage folder. Make the livechat folder writable and try again.'];
        }
        $this->protectStorage($storageDir);

        if ($clean['driver'] === 'sqlite') {
            // random file name: if a badly configured server ever ignores our
            // .htaccess, nobody can guess where the database is
            $db = ['driver' => 'sqlite', 'file' => 'livechat-' . bin2hex(random_bytes(8)) . '.sqlite'];
        } else {
            $db = [
                'driver' => 'mysql',
                'host'   => $clean['mysql_host'],
                'port'   => $clean['mysql_port'],
                'name'   => $clean['mysql_name'],
                'user'   => $clean['mysql_user'],
                'pass'   => $clean['mysql_pass'],
            ];
        }

        try {
            $pdo = App::connect($db, $this->dir);
        } catch (PDOException $e) {
            $this->discard($db);
            return ['Could not connect to the database: ' . $e->getMessage()];
        }

        // never overwrite somebody's existing chats
        try {
            $pdo->query('SELECT 1 FROM agents LIMIT 1');
            return ['This database already has LiveChat tables in it. Use an empty database.'];
        } catch (PDOException) {
            // no such table: exactly what we want
        }

        try {
            $this->runSchema($pdo, $db['driver']);
            (new AgentRepository($pdo))->create($clean['admin_name'], $clean['admin_email'], $clean['admin_password'], true);
            $this->writeConfig($clean, $db, $env);
        } catch (\Throwable $e) {
            $this->rollBack($pdo, $db);
            return ['Installation failed: ' . $e->getMessage()];
        }

        return [];
    }

    private function validate(array $in, ?array &$clean): array
    {
        $errors = [];
        $get = fn (string $key): string => trim((string) ($in[$key] ?? ''));

        $driver = $get('driver');
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            $errors[] = 'Pick a database type.';
        }

        $clean = [
            'driver'         => $driver,
            'mysql_host'     => $get('mysql_host') ?: 'localhost',
            'mysql_port'     => (int) ($get('mysql_port') ?: 3306),
            'mysql_name'     => $get('mysql_name'),
            'mysql_user'     => $get('mysql_user'),
            'mysql_pass'     => (string) ($in['mysql_pass'] ?? ''),
            'admin_name'     => $get('admin_name'),
            'admin_email'    => strtolower($get('admin_email')),
            'admin_password' => (string) ($in['admin_password'] ?? ''),
            'site_name'      => $get('site_name') ?: 'Live chat',
            'notify_email'   => strtolower($get('notify_email')),
            'mail_driver'    => $get('mail_driver') === 'log' ? 'log' : 'mail',
        ];

        if ($driver === 'mysql' && ($clean['mysql_name'] === '' || $clean['mysql_user'] === '')) {
            $errors[] = 'MySQL needs a database name and a user.';
        }
        if ($clean['admin_name'] === '' || strlen($clean['admin_name']) > 100) {
            $errors[] = 'Enter your name (up to 100 characters).';
        }
        if (!filter_var($clean['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address for your login.';
        }
        if (strlen($clean['admin_password']) < 10) {
            $errors[] = 'The password needs at least 10 characters.';
        }
        if (strlen($clean['admin_password']) > 200) {
            $errors[] = 'That password is too long (200 characters max).';
        }
        if (!hash_equals($clean['admin_password'], (string) ($in['admin_password2'] ?? ''))) {
            $errors[] = 'The two passwords do not match.';
        }
        if (strlen($clean['site_name']) > 80) {
            $errors[] = 'The site name is too long (80 characters max).';
        }
        if ($clean['notify_email'] === '') {
            $clean['notify_email'] = $clean['admin_email'];
        } elseif (!filter_var($clean['notify_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The notification email is not valid.';
        }

        return $errors;
    }

    // Statements are split on ";", which is safe because our own schema files
    // never have one inside a string or comment.
    private function runSchema(PDO $pdo, string $driver): void
    {
        $sql = (string) file_get_contents($this->schemaDir . '/' . $driver . '.sql');
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function writeConfig(array $clean, array $db, array $env): void
    {
        $host = preg_replace('/:\d+$/', '', (string) ($env['host'] ?? 'localhost'));

        $config = [
            'installed_at'     => gmdate('c'),
            'site_name'        => $clean['site_name'],
            'base_url'         => $env['base_url'],
            'db'               => $db,
            'notify_email'     => $clean['notify_email'],
            'mail'             => ['driver' => $clean['mail_driver'], 'from' => 'chat@' . $host],
            'poll_interval_ms' => 3000,
        ];

        $php = "<?php\n// Written by the LiveChat installer. Contains your database password: never share or commit it.\nreturn "
            . var_export($config, true) . ";\n";

        // write to a temp file and rename, so a crash can't leave half a config behind
        $path = App::configPath($this->dir);
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the config file.');
        }
        @chmod($tmp, 0640);
        rename($tmp, $path);
    }

    // storage/ holds the database, the config and the mail log, so keep the
    // web server from serving anything out of it.
    private function protectStorage(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            file_put_contents($dir . '/index.html', '');
        }
    }

    // Put things back the way they were if setup fails halfway,
    // so the user can simply try again.
    private function rollBack(PDO $pdo, array $db): void
    {
        if ($db['driver'] === 'sqlite') {
            $pdo = null;
            $this->discard($db);
            return;
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['messages', 'conversations', 'rate_limits', 'agents'] as $table) {
                $pdo->exec("DROP TABLE IF EXISTS $table");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (PDOException) {
            // nothing more we can do
        }
    }

    private function discard(array $db): void
    {
        if ($db['driver'] === 'sqlite') {
            @unlink($this->dir . '/storage/' . $db['file']);
        }
    }
}
