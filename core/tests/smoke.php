<?php
// php core/tests/smoke.php  -- runs the whole chat flow with in-memory fakes, no framework, no DB.
declare(strict_types=1);
spl_autoload_register(fn ($c) => is_file($f = __DIR__ . '/../src/' . str_replace(['LiveChat\\Core\\', '\\'], ['', '/'], $c) . '.php') ? require $f : null);

use LiveChat\Core\Contracts\{AgentAuthenticatorInterface, NotifierInterface, RateLimiterInterface, StorageInterface};
use LiveChat\Core\Domain\{Agent, Conversation, Message};
use LiveChat\Core\Http\{ApiRouter, Request};
use LiveChat\Core\Service\{ChatService, PollingTransport};

final class MemStorage implements StorageInterface {
    public array $convs = [], $msgs = [], $tokens = [], $presence = [];
    public function createConversation(string $h, array $meta): Conversation { $id = count($this->convs) + 1; $now = new DateTimeImmutable();
        $this->tokens[$h] = $id; return $this->convs[$id] = new Conversation($id, 'open', $meta, null, $now, $now); }
    public function findConversation(int $id): ?Conversation { return $this->convs[$id] ?? null; }
    public function findConversationByTokenHash(string $h): ?Conversation { return isset($this->tokens[$h]) ? $this->convs[$this->tokens[$h]] : null; }
    public function listConversations(string $s, int $l = 50, int $o = 0): array { return array_values($this->convs); }
    public function setConversationStatus(int $id, string $s): void { $c = $this->convs[$id]; $this->convs[$id] = new Conversation($id, $s, $c->visitorMeta, null, $c->createdAt, $c->lastActivityAt); }
    public function assignConversation(int $id, ?int $a): void {}
    public function addMessage(int $cid, string $t, ?int $a, string $b, ?string $cl = null): Message {
        foreach ($this->msgs as $m) if ($cl && $m->conversationId === $cid && $m->clientId === $cl) return $m;   // idempotent
        $id = count($this->msgs) + 1; return $this->msgs[$id] = new Message($id, $cid, $t, $a, $b, $cl, new DateTimeImmutable()); }
    public function findMessageByClientId(int $cid, string $cl): ?Message { foreach ($this->msgs as $m) if ($m->conversationId === $cid && $m->clientId === $cl) return $m; return null; }
    public function messagesSince(int $cid, int $after, int $l = 100): array { return array_values(array_filter($this->msgs, fn ($m) => $m->conversationId === $cid && $m->id > $after)); }
    public function allMessagesSince(int $after, int $l = 100): array { return array_values(array_filter($this->msgs, fn ($m) => $m->id > $after)); }
    public function findAgent(int $id): ?Agent { return new Agent(1, 'Ada', 'ada@x.test'); }
    public function touchAgentPresence(int $id): void { $this->presence[$id] = time(); }
    public function countOnlineAgents(int $w = 60): int { return count($this->presence); }
    public function listAgents(): array { return []; }
}
$notified = 0;
$notifier = new class($notified) implements NotifierInterface { public function __construct(private int &$n) {}
    public function newVisitorMessage(Conversation $c, Message $m): void { $this->n++; } public function offlineMessage(string $a, string $b, string $c): void {} };
$limiter = new class implements RateLimiterInterface { private array $h = [];
    public function hit(string $k, int $max, int $s): bool { return ($this->h[$k] = ($this->h[$k] ?? 0) + 1) <= $max; } };
$auth = new class implements AgentAuthenticatorInterface {
    public function authenticate(Request $r): ?Agent { return $r->header('authorization') === 'Bearer agent' ? new Agent(1, 'Ada', 'ada@x.test') : null; } };

$store = new MemStorage();
$api = new ApiRouter(new ChatService($store, $notifier, new PollingTransport(), $limiter), $store, new PollingTransport(), $auth);
$call = fn (string $m, string $p, array $body = [], array $q = [], array $h = []) => $api->handle(new Request($m, $p, $q, $body, $h, '1.2.3.4'));
$ok = function (string $name, bool $cond) { echo ($cond ? "PASS" : "FAIL") . "  $name\n"; if (!$cond) exit(1); };

$r = $call('POST', '/conversations', ['name' => 'Sam']);                       $ok('start conversation (201)', $r->status === 201);
$tok = $r->data['visitor_token'];
$r = $call('POST', '/messages', ['body' => 'Hi, price?', 'client_id' => 'a1'], [], ['x-chat-token' => $tok]); $ok('visitor sends message', $r->status === 201);
$call('POST', '/messages', ['body' => 'Hi, price?', 'client_id' => 'a1'], [], ['x-chat-token' => $tok]);               $ok('retry with same client_id is idempotent', count($store->msgs) === 1);
$ok('agent notified exactly once (retry did not re-notify)', $notified === 1);
$r = $call('GET', '/agent/events');                                            $ok('agent endpoint without auth -> 401', $r->status === 401);
$r = $call('GET', '/agent/events', [], ['after' => 0], ['authorization' => 'Bearer agent']); $ok('agent sees visitor message', count($r->data['messages']) === 1);
$r = $call('POST', '/agent/conversations/1/messages', ['body' => 'About $20'], [], ['authorization' => 'Bearer agent']); $ok('agent replies', $r->status === 201);
$r = $call('GET', '/messages', [], ['after' => 0], ['x-chat-token' => $tok]); $ok('visitor polls both messages', count($r->data['messages']) === 2 && $r->data['agent_online'] === true);
$r = $call('GET', '/messages', [], ['after' => 0], ['x-chat-token' => 'wrong']); $ok('wrong token -> 401', $r->status === 401);
$r = $call('POST', '/messages', ['body' => str_repeat('x', 3000)], [], ['x-chat-token' => $tok]); $ok('too-long message -> 422', $r->status === 422);
$call('POST', '/agent/conversations/1/close', [], [], ['authorization' => 'Bearer agent']);
$r = $call('POST', '/messages', ['body' => 'still there?'], [], ['x-chat-token' => $tok]); $ok('closed conversation -> 409', $r->status === 409);
for ($i = 0; $i < 6; $i++) $r = $call('POST', '/conversations');               $ok('conversation spam rate-limited -> 429', $r->status === 429);
echo "\nAll good.\n";
