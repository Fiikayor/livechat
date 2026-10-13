<?php
declare(strict_types=1);

namespace LiveChat\Core\Service;

use LiveChat\Core\Contracts\TransportInterface;

// Works on any host. Nothing to push, the browser just asks
// again every few seconds and the DB already has the answer.
final class PollingTransport implements TransportInterface
{
    public function __construct(private int $intervalMs = 3000) {}

    public function publish(string $channel, string $event, array $payload): void {}

    public function clientConfig(): array
    {
        return ['mode' => 'polling', 'interval_ms' => $this->intervalMs];
    }
}