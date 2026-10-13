<?php
declare(strict_types=1);

namespace LiveChat\Core\Contracts;

interface RateLimiterInterface
{
    // true = allowed, false = over the limit
    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool;
}