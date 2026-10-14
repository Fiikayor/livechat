<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Contracts\RateLimiterInterface;
use PDO;
use PDOException;

// Counts hits per key in the rate_limits table. Fixed window: the first hit
// opens a window, hits inside it pile up, and once $decaySeconds have passed
// the counter starts over. Not perfectly precise, but plenty for stopping spam.
final class PdoRateLimiter implements RateLimiterInterface
{
    public function __construct(private PDO $pdo) {}

    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $now = time();

        $select = $this->pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = ?');
        $select->execute([$key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        // no window yet, or the old one expired: start a fresh one
        if (!$row || ($now - (int) $row['window_start']) >= $decaySeconds) {
            $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$key]);

            try {
                $this->pdo->prepare('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)')
                    ->execute([$key, $now]);
                return 1 <= $maxAttempts;
            } catch (PDOException) {
                // someone else opened the window a split second before us,
                // so fall through and count this as one more hit
            }
        }

        $this->pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$key]);

        $select->execute([$key]);
        $hits = (int) ($select->fetchColumn() ?: 1);

        return $hits <= $maxAttempts;
    }
}