<?php
declare(strict_types=1);

namespace LiveChat\Core\Domain;

final class Message
{
    public const FROM_VISITOR = 'visitor';
    public const FROM_AGENT = 'agent';
    public const FROM_SYSTEM = 'system';   // not used yet, for "chat closed" style notes

    public function __construct(
        public readonly int $id,
        public readonly int $conversationId,
        public readonly string $senderType,
        public readonly ?int $agentId,
        public readonly string $body,         // raw text, escape when you render it
        public readonly ?string $clientId,    // lets us ignore retried sends
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'sender' => $this->senderType,
            'agent_id' => $this->agentId,
            'body' => $this->body,
            'client_id' => $this->clientId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}