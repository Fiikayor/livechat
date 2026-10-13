<?php
declare(strict_types=1);
namespace LiveChat\Core\Domain;

final class Agent
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isAdmin = false,
    ) {}
}
