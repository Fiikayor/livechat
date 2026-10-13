<?php
declare(strict_types=1);
namespace LiveChat\Core\Contracts;

use LiveChat\Core\Domain\{Agent, Conversation, Message};

/**
 * Standalone: PDO (SQLite/MySQL).  Laravel: Eloquent.
 * The core never touches a database directly.
 */
interface StorageInterface
{
    // --- conversations ---
    public function createConversation(string $visitorTokenHash, array $visitorMeta): Conversation;
    public function findConversation(int $id): ?Conversation;
    public function findConversationByTokenHash(string $visitorTokenHash): ?Conversation;
    /** @return Conversation[] */
    public function listConversations(string $status, int $limit = 50, int $offset = 0): array;
    public function setConversationStatus(int $id, string $status): void;
    public function assignConversation(int $id, ?int $agentId): void;

    // --- messages ---
    /** Must be idempotent on ($conversationId, $clientId): return the existing row if repeated. */
    public function addMessage(
        int $conversationId,
        string $senderType,
        ?int $agentId,
        string $body,
        ?string $clientId = null
    ): Message;

    /** Used by the core to skip notifications/broadcasts for retried (duplicate) sends. */
    public function findMessageByClientId(int $conversationId, string $clientId): ?Message;

    /** @return Message[] ordered by id ASC, only ids > $afterId */
    public function messagesSince(int $conversationId, int $afterId, int $limit = 100): array;

    /** Dashboard feed: new messages across ALL conversations. @return Message[] */
    public function allMessagesSince(int $afterId, int $limit = 100): array;

    // --- agents & presence ---
    public function findAgent(int $id): ?Agent;
    public function touchAgentPresence(int $agentId): void;
    public function countOnlineAgents(int $withinSeconds = 60): int;

    /** @return Agent[] */
    public function listAgents(): array;
}
