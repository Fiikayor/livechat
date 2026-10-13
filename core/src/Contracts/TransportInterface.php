<?php
declare(strict_types=1);
namespace LiveChat\Core\Contracts;

/**
 * How new data reaches the browser.
 *
 * PollingTransport: publish() is a no-op (storage is the source of truth),
 *                   clientConfig() tells the widget to poll every N ms.
 * ReverbTransport:  publish() broadcasts, clientConfig() returns ws host/key.
 */
interface TransportInterface
{
    /** @param array<string,mixed> $payload */
    public function publish(string $channel, string $event, array $payload): void;

    /** Sent to the widget/dashboard via GET /config so they pick the right mode. */
    public function clientConfig(): array;
}
