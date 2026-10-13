<?php

declare(strict_types=1);

namespace LiveChat\Core\Contracts;

interface TransportInterface
{
    /** @param array<string,mixed> $payload */
    public function publish(string $channel, string $event, array $payload): void;

    // sent to the widget so it knows whether to poll or connect
    public function clientConfig(): array;
}
