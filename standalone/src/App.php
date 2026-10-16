<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Http\ApiRouter;
use LiveChat\Core\Service\{ChatService, PollingTransport};
use LiveChat\Core\Support\ChatException;
use PDO;

// Reads the config the installer wrote and wires the core to the real
// database, mailer and login. This is the standalone version's "composition root".
final class App
{
    public function __construct(
        public readonly ApiRouter $router,
        public readonly AuthEndpoint $auth,
        public readonly MailNotifier $notifier,
        public readonly array $config,
    ) {}

    // config lives in storage/ so it sits behind the same "no web access" rule as the database
    public static function configPath(string $dir): string
    {
        return $dir . '/storage/config.php';
    }

    public static function boot(string $dir): self
    {
        $file = self::configPath($dir);
        if (!is_file($file)) {
            throw new ChatException('not_installed', 'LiveChat is not installed yet. Open install.php first.', 503);
        }

        $config = require $file;
        $pdo = self::connect($config['db'], $dir);

        $limiter = new PdoRateLimiter($pdo);
        $storage = new PdoStorage($pdo);

        $notifier = new MailNotifier(
            $config['notify_email'],
            $config['mail']['from'],
            $config['site_name'],
            $config['base_url'],
            $config['mail']['driver'],
            $dir . '/storage/mail.log',
            $limiter
        );

        $transport = new PollingTransport((int) ($config['poll_interval_ms'] ?? 3000));
        $chat = new ChatService($storage, $notifier, $transport, $limiter);
        $router = new ApiRouter($chat, $storage, $transport, new SessionAgentAuthenticator($storage));
        $auth = new AuthEndpoint(new AgentRepository($pdo), $storage, $limiter);

        return new self($router, $auth, $notifier, $config);
    }

    public static function connect(array $db, string $dir): PDO
    {
        if ($db['driver'] === 'sqlite') {
            $pdo = new PDO('sqlite:' . $dir . '/storage/' . $db['file']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');    // SQLite ignores foreign keys unless told to
            $pdo->exec('PRAGMA busy_timeout = 5000');  // wait up to 5s if another request is writing
            return $pdo;
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']),
            $db['user'],
            $db['pass']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
