<?php
declare(strict_types=1);

namespace LiveChat\Core\Support;

final class Token
{
    // The visitor gets this once and keeps it in localStorage.
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    // Only the hash goes in the DB, so a leaked database
    // doesn't let anyone read other people's chats.
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}