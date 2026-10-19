<?php
declare(strict_types=1);

namespace LiveChat\Core\Contracts;

use LiveChat\Core\Domain\{Agent, Conversation, Message};

// Everything the core needs from a database. Standalone uses PDO,
// Laravel uses Eloquent. Keep SQL out of the core.
interface StorageInterface
{
    // we only ever get the token hash here, never the real token
    public function createConversation(string $visitorTokenHash, array $visitorMeta): Conversation;
    public function findConversation(int $id): ?Conversation;
    public function findConversationByTokenHash(string $visitorTokenHash): ?Conversation;

    /** @return Conversation[] */
    public function listConversations(string $status, int $limit = 50, int $offset = 0): array;

    public function setConversationStatus(int $id, string $status): void;
    public function assignConversation(int $id, ?int $agentId): void;

    // Must be safe to call twice with the same clientId: return the
    // existing message instead of inserting a duplicate.
    public function addMessage(
        int $conversationId,
        string $senderType,
        ?int $agentId,
        string $body,
        ?string $clientId = null
    ): Message;

    public function findMessageByClientId(int $conversationId, string $clientId): ?Message;

    // oldest first, only ids greater than $afterId. This is what polling uses.
    /** @return Message[] */
    public function messagesSince(int $conversationId, int $afterId, int $limit = 100): array;

    // same thing across every conversation, for the dashboard
    /** @return Message[] */
    public function allMessagesSince(int $afterId, int $limit = 100): array;

    // The newest message of each given conversation, keyed by conversation id.
    // The dashboard shows it as the preview line in the conversation list.
    /** @param int[] $conversationIds  @return array<int, Message> */
    public function lastMessages(array $conversationIds): array;

    public function findAgent(int $id): ?Agent;
    public function touchAgentPresence(int $agentId): void;
    public function countOnlineAgents(int $withinSeconds = 60): int;

    /** @return Agent[] */
    public function listAgents(): array;
}