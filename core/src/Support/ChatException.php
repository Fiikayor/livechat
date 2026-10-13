<?php

declare(strict_types=1);

namespace LiveChat\Core\Support;

// For errors the visitor/agent caused (bad input, rate limit, etc).
// errorCode is for the JS to check, httpStatus is for the response.
final class ChatException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
