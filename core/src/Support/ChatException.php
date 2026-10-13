<?php
declare(strict_types=1);
namespace LiveChat\Core\Support;

final class ChatException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,   // machine-readable, e.g. "rate_limited"
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
