<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use DateTimeImmutable;
use DateTimeZone;
use LiveChat\Core\Contracts\StorageInterface;
use LiveChat\Core\Domain\{Agent, Conversation, Message};
use PDO;
use PDOException;

// StorageInterface on top of PDO. Written to run unchanged on SQLite and
// MySQL, so no vendor-specific SQL in here (no INSERT OR IGNORE, no
// ON DUPLICATE KEY, etc). That's why timestamps are generated in PHP.
final class PdoStorage implements StorageInterface
{
    public function __construct(private PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    // ---------- conversations ----------

    public function createConversation(string $visitorTokenHash, array $visitorMeta): Conversation
    {
        $now = self::now();

        $this->run(
            'INSERT INTO conversations (token_hash, status, visitor_meta, created_at, last_activity_at)
             VALUES (?, ?, ?, ?, ?)',
            [$visitorTokenHash, Conversation::OPEN, json_encode($visitorMeta, JSON_UNESCAPED_UNICODE), $now, $now]
        );

        return $this->findConversation((int) $this->pdo->lastInsertId())
            ?? throw new \RuntimeException('Conversation vanished right after insert.');
    }

    public function findConversation(int $id): ?Conversation
    {
        $row = $this->run('SELECT * FROM conversations WHERE id = ?', [$id])->fetch();
        return $row ? $this->toConversation($row) : null;
    }

    public function findConversationByTokenHash(string $visitorTokenHash): ?Conversation
    {
        $row = $this->run('SELECT * FROM conversations WHERE token_hash = ?', [$visitorTokenHash])->fetch();
        return $row ? $this->toConversation($row) : null;
    }

    public function listConversations(string $status, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->run(
            'SELECT * FROM conversations WHERE status = ? ORDER BY last_activity_at DESC, id DESC LIMIT ? OFFSET ?',
            [$status, $limit, $offset]
        )->fetchAll();

        return array_map([$this, 'toConversation'], $rows);
    }

    public function setConversationStatus(int $id, string $status): void
    {
        $this->run('UPDATE conversations SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function assignConversation(int $id, ?int $agentId): void
    {
        $this->run('UPDATE conversations SET assigned_agent_id = ? WHERE id = ?', [$agentId, $id]);
    }

    // ---------- messages ----------

    public function addMessage(
        int $conversationId,
        string $senderType,
        ?int $agentId,
        string $body,
        ?string $clientId = null
    ): Message {
        // Retry of something we already saved? Hand back the original.
        if ($clientId !== null && ($existing = $this->findMessageByClientId($conversationId, $clientId))) {
            return $existing;
        }

        try {
            $this->run(
                'INSERT INTO messages (conversation_id, sender_type, agent_id, body, client_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$conversationId, $senderType, $agentId, $body, $clientId, self::now()]
            );
        } catch (PDOException $e) {
            // Two identical retries can slip past the check above at the same
            // moment. The UNIQUE index stops the second one, so just return
            // the winner. Anything else is a real error.
            if ($clientId !== null && ($existing = $this->findMessageByClientId($conversationId, $clientId))) {
                return $existing;
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();
        $this->run('UPDATE conversations SET last_activity_at = ? WHERE id = ?', [self::now(), $conversationId]);

        return $this->findMessage($id)
            ?? throw new \RuntimeException('Message vanished right after insert.');
    }

    public function findMessageByClientId(int $conversationId, string $clientId): ?Message
    {
        $row = $this->run(
            'SELECT * FROM messages WHERE conversation_id = ? AND client_id = ?',
            [$conversationId, $clientId]
        )->fetch();

        return $row ? $this->toMessage($row) : null;
    }

    public function messagesSince(int $conversationId, int $afterId, int $limit = 100): array
    {
        $rows = $this->run(
            'SELECT * FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT ?',
            [$conversationId, $afterId, $limit]
        )->fetchAll();

        return array_map([$this, 'toMessage'], $rows);
    }

    public function allMessagesSince(int $afterId, int $limit = 100): array
    {
        $rows = $this->run(
            'SELECT * FROM messages WHERE id > ? ORDER BY id ASC LIMIT ?',
            [$afterId, $limit]
        )->fetchAll();

        return array_map([$this, 'toMessage'], $rows);
    }

    public function lastMessages(array $conversationIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $conversationIds)));
        if (!$ids) {
            return [];
        }

        // newest message id per conversation, then fetch exactly those rows.
        // $marks is only ever "?,?,?", the ids themselves are bound as values.
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->run(
            "SELECT * FROM messages WHERE id IN (
                SELECT MAX(id) FROM messages WHERE conversation_id IN ($marks) GROUP BY conversation_id
            )",
            $ids
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['conversation_id']] = $this->toMessage($row);
        }
        return $out;
    }

    // ---------- agents ----------

    public function findAgent(int $id): ?Agent
    {
        $row = $this->run('SELECT * FROM agents WHERE id = ?', [$id])->fetch();
        return $row ? $this->toAgent($row) : null;
    }

    public function touchAgentPresence(int $agentId): void
    {
        $this->run('UPDATE agents SET last_seen_at = ? WHERE id = ?', [time(), $agentId]);
    }

    public function countOnlineAgents(int $withinSeconds = 60): int
    {
        $row = $this->run('SELECT COUNT(*) AS n FROM agents WHERE last_seen_at >= ?', [time() - $withinSeconds])->fetch();
        return (int) $row['n'];
    }

    public function listAgents(): array
    {
        return array_map([$this, 'toAgent'], $this->run('SELECT * FROM agents ORDER BY id')->fetchAll());
    }

    // ---------- internals ----------

    private function findMessage(int $id): ?Message
    {
        $row = $this->run('SELECT * FROM messages WHERE id = ?', [$id])->fetch();
        return $row ? $this->toMessage($row) : null;
    }

    // Prepare + bind + execute in one go. Ints are bound as ints, which
    // matters for LIMIT on MySQL (a quoted '50' is a syntax error there).
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }

        $stmt->execute();
        return $stmt;
    }

    // Everything is stored in UTC so the server's timezone never matters.
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function toConversation(array $r): Conversation
    {
        return new Conversation(
            (int) $r['id'],
            $r['status'],
            json_decode($r['visitor_meta'] ?: '{}', true) ?: [],
            $r['assigned_agent_id'] !== null ? (int) $r['assigned_agent_id'] : null,
            self::date($r['created_at']),
            self::date($r['last_activity_at'])
        );
    }

    private function toMessage(array $r): Message
    {
        return new Message(
            (int) $r['id'],
            (int) $r['conversation_id'],
            $r['sender_type'],
            $r['agent_id'] !== null ? (int) $r['agent_id'] : null,
            $r['body'],
            $r['client_id'],
            self::date($r['created_at'])
        );
    }

    private function toAgent(array $r): Agent
    {
        return new Agent((int) $r['id'], $r['name'], $r['email'], (bool) $r['is_admin']);
    }
}