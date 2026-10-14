<?php
// Runs the chat story against in-memory fakes. No DB needed:
//   php core/tests/smoke.php
declare(strict_types=1);

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../src/' . str_replace(['LiveChat\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require __DIR__ . '/story.php';

use LiveChat\Core\Contracts\{RateLimiterInterface, StorageInterface};
use LiveChat\Core\Domain\{Agent, Conversation, Message};

// fake storage, just arrays
final class MemStorage implements StorageInterface
{
    public array $convs = [];
    public array $msgs = [];
    public array $tokens = [];     // token hash => conversation id
    public array $presence = [];

    public function createConversation(string $h, array $meta): Conversation
    {
        $id = count($this->convs) + 1;
        $now = new DateTimeImmutable();
        $this->tokens[$h] = $id;
        return $this->convs[$id] = new Conversation($id, 'open', $meta, null, $now, $now);
    }

    public function findConversation(int $id): ?Conversation
    {
        return $this->convs[$id] ?? null;
    }

    public function findConversationByTokenHash(string $h): ?Conversation
    {
        return isset($this->tokens[$h]) ? $this->convs[$this->tokens[$h]] : null;
    }

    public function listConversations(string $s, int $l = 50, int $o = 0): array
    {
        return array_values(array_filter($this->convs, fn($c) => $c->status === $s));
    }

    public function setConversationStatus(int $id, string $s): void
    {
        $c = $this->convs[$id];
        $this->convs[$id] = new Conversation($id, $s, $c->visitorMeta, null, $c->createdAt, $c->lastActivityAt);
    }

    public function assignConversation(int $id, ?int $a): void {}

    public function addMessage(int $cid, string $t, ?int $a, string $b, ?string $cl = null): Message
    {
        if ($cl !== null && ($m = $this->findMessageByClientId($cid, $cl))) {
            return $m;
        }
        $id = count($this->msgs) + 1;
        return $this->msgs[$id] = new Message($id, $cid, $t, $a, $b, $cl, new DateTimeImmutable());
    }

    public function findMessageByClientId(int $cid, string $cl): ?Message
    {
        foreach ($this->msgs as $m) {
            if ($m->conversationId === $cid && $m->clientId === $cl) {
                return $m;
            }
        }
        return null;
    }

    public function messagesSince(int $cid, int $after, int $l = 100): array
    {
        return array_values(array_filter($this->msgs, fn($m) => $m->conversationId === $cid && $m->id > $after));
    }

    public function allMessagesSince(int $after, int $l = 100): array
    {
        return array_values(array_filter($this->msgs, fn($m) => $m->id > $after));
    }

    public function findAgent(int $id): ?Agent
    {
        return new Agent(1, 'Ada', 'ada@x.test');
    }

    public function touchAgentPresence(int $id): void
    {
        $this->presence[$id] = time();
    }

    public function countOnlineAgents(int $w = 60): int
    {
        return count($this->presence);
    }

    public function listAgents(): array
    {
        return [];
    }
}

$limiter = new class implements RateLimiterInterface {
    private array $h = [];
    public function hit(string $k, int $max, int $s): bool
    {
        return ($this->h[$k] = ($this->h[$k] ?? 0) + 1) <= $max;
    }
};

livechat_run_story(new MemStorage(), $limiter);
