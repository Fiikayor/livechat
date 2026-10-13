<?php
declare(strict_types=1);

namespace LiveChat\Core\Http;

// Framework-neutral request. Each wrapper builds one of these from
// its own request object.
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,           // relative to the API base, e.g. "/messages"
        public readonly array $query = [],
        public readonly array $body = [],       // already json-decoded
        public readonly array $headers = [],    // keys must be lowercase
        public readonly string $ip = '0.0.0.0',
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function visitorToken(): ?string
    {
        return $this->header('x-chat-token');
    }
}