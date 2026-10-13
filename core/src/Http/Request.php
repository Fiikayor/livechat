<?php
declare(strict_types=1);
namespace LiveChat\Core\Http;

/** Wrappers build this from $_SERVER (standalone) or Illuminate\Http\Request (Laravel). */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,          // relative to the API base, e.g. "/messages"
        public readonly array $query = [],
        public readonly array $body = [],      // already JSON-decoded
        public readonly array $headers = [],   // lower-cased keys
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
