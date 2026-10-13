<?php

declare(strict_types=1);

namespace LiveChat\Core\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly array $headers = ['Content-Type' => 'application/json'],
    ) {}

    public function json(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
