<?php
declare(strict_types=1);
namespace LiveChat\Core\Contracts;

/**
 * Standalone: table/file based.  Laravel: RateLimiter facade.
 */
interface RateLimiterInterface
{
    /** @return bool true if allowed, false if the limit is exceeded */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool;
}
