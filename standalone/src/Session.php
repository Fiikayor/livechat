<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

// Only agents get PHP sessions. Visitors use their token, so none of this
// runs for them.
final class Session
{
    public const NAME = 'livechat_sid';

    // Read the session and let go of it straight away. PHP locks the session
    // file while it's open, and we don't want the dashboard's polling request
    // to block the agent's own "send reply" request.
    public static function read(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return $_SESSION;
        }
        if (!isset($_COOKIE[self::NAME])) {
            return [];
        }

        self::configure();
        session_start();
        $data = $_SESSION;
        session_write_close();   // not read_and_close: this also refreshes the file's timestamp,
                                 // which keeps an active agent from being logged out mid-shift
        return $data;
    }

    // Open for writing (login, logout, installer). Remember to close it.
    public static function open(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::configure();
            session_start();
        }
    }

    public static function destroy(): void
    {
        self::open();
        $_SESSION = [];
        setcookie(self::NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }

    private static function configure(): void
    {
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,                 // gone when the browser closes
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,              // JavaScript can't read the cookie
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '28800');   // 8 hours of inactivity
    }

    private static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
