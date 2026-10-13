<?php
declare(strict_types=1);
namespace LiveChat\Core\Support;

final class Token
{
    /** Plain token goes to the visitor once; only the hash is stored. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
