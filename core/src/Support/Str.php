<?php

declare(strict_types=1);

namespace LiveChat\Core\Support;

// mbstring isn't enabled on every cheap host, so fall back to regex
// instead of crashing.
final class Str
{
    public static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : (int) preg_match_all('/./us', $s);
    }

    public static function cut(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max);
        }
        preg_match('/^.{0,' . $max . '}/us', $s, $m);
        return $m[0] ?? '';
    }
}
