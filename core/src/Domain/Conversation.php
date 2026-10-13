<?php
declare(strict_types=1);

namespace LiveChat\Core\Domain;

final class Conversation
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    public function __construct(
        public readonly int $id,
        public readonly string $status,
        public readonly array $visitorMeta,   // name, email, page url, etc
        public readonly ?int $assignedAgentId,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $lastActivityAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'visitor' => $this->visitorMeta,
            'assigned_agent_id' => $this->assignedAgentId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'last_activity_at' => $this->lastActivityAt->format(DATE_ATOM),
        ];
    }
}