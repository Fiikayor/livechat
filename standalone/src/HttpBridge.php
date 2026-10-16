<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Http\{Request, Response};

// Translates between plain PHP ($_SERVER, echo) and the core's Request/Response.
final class HttpBridge
{
    public static function request(): Request
    {
        // Normally the URL looks like api.php/messages. A few servers don't
        // pass that part along, so api.php?route=/messages works too.
        $path = '/' . ltrim((string) ($_SERVER['PATH_INFO'] ?? $_GET['route'] ?? '/'), '/');

        // nobody needs to send us more than 64 KB of JSON
        $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
        $body = json_decode($raw, true, 8);

        // header names, lowercased: HTTP_X_CHAT_TOKEN becomes x-chat-token
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        return new Request(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $_GET,
            is_array($body) ? $body : [],
            $headers,
            // REMOTE_ADDR on purpose, not X-Forwarded-For: that header is
            // trivial to fake, which would make rate limiting pointless
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        );
    }

    public static function send(Response $response): void
    {
        $json = $response->json();

        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header("$name: $value");
        }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($json));

        echo $json;
    }

    // Hand the finished reply to the visitor so the script can keep working
    // (sending email) without making them wait.
    public static function finish(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
