<?php
// One chat scenario, run against whatever storage you hand it.
// smoke.php uses in-memory fakes, standalone/tests/pdo_smoke.php uses a real
// SQLite database. If both pass, the two behave the same.
//
// The storage must already contain an agent with id 1.
declare(strict_types=1);

use LiveChat\Core\Contracts\{AgentAuthenticatorInterface, NotifierInterface, RateLimiterInterface, StorageInterface};
use LiveChat\Core\Domain\{Agent, Conversation, Message};
use LiveChat\Core\Http\{ApiRouter, Request};
use LiveChat\Core\Service\{ChatService, PollingTransport};

function livechat_run_story(StorageInterface $store, RateLimiterInterface $limiter): void
{
    // counts how many times the team would have been alerted
    $notified = 0;
    $notifier = new class($notified) implements NotifierInterface {
        public function __construct(private int &$n) {}
        public function newVisitorMessage(Conversation $c, Message $m): void
        {
            $this->n++;
        }
        public function offlineMessage(string $a, string $b, string $c): void {}
    };

    // "Bearer agent" is all it takes to be logged in here
    $auth = new class($store) implements AgentAuthenticatorInterface {
        public function __construct(private StorageInterface $store) {}
        public function authenticate(Request $r): ?Agent
        {
            return $r->header('authorization') === 'Bearer agent' ? $this->store->findAgent(1) : null;
        }
    };

    $api = new ApiRouter(
        new ChatService($store, $notifier, new PollingTransport(), $limiter),
        $store,
        new PollingTransport(),
        $auth
    );

    $call = fn(string $m, string $p, array $body = [], array $q = [], array $h = [])
    => $api->handle(new Request($m, $p, $q, $body, $h, '1.2.3.4'));

    $ok = function (string $name, bool $cond) {
        echo ($cond ? 'PASS' : 'FAIL') . "  $name\n";
        if (!$cond) {
            exit(1);
        }
    };

    $agent = ['authorization' => 'Bearer agent'];

    // nobody is online yet
    $r = $call('GET', '/config');
    $ok('config says no agent online at the start', $r->data['agent_online'] === false);

    // visitor starts a chat
    $r = $call('POST', '/conversations', ['name' => 'Sam', 'evil' => 'ignored']);
    $ok('start conversation (201)', $r->status === 201);
    $tok = $r->data['visitor_token'];
    $ok('unexpected meta fields are dropped', !isset($store->findConversation(1)->visitorMeta['evil']));

    $r = $call('POST', '/messages', ['body' => 'Hi, price?', 'client_id' => 'a1'], [], ['x-chat-token' => $tok]);
    $ok('visitor sends message', $r->status === 201);

    // same client_id again, like a retry after a bad connection
    $call('POST', '/messages', ['body' => 'Hi, price?', 'client_id' => 'a1'], [], ['x-chat-token' => $tok]);
    $ok('retry with same client_id is idempotent', count($store->messagesSince(1, 0)) === 1);
    $ok('agent notified exactly once (retry did not re-notify)', $notified === 1);

    $r = $call('GET', '/agent/events');
    $ok('agent endpoint without auth -> 401', $r->status === 401);

    $r = $call('GET', '/agent/events', [], ['after' => 0], $agent);
    $ok('agent sees visitor message', count($r->data['messages']) === 1);

    $r = $call('POST', '/agent/conversations/1/messages', ['body' => 'About $20 and emoji 🙂'], [], $agent);
    $ok('agent replies', $r->status === 201);

    $r = $call('GET', '/messages', [], ['after' => 0], ['x-chat-token' => $tok]);
    $ok('visitor polls both messages', count($r->data['messages']) === 2 && $r->data['agent_online'] === true);
    $ok('emoji survives the round trip', str_contains($r->data['messages'][1]['body'], '🙂'));

    // polling again from the cursor should return nothing new
    $r = $call('GET', '/messages', [], ['after' => $r->data['next_after']], ['x-chat-token' => $tok]);
    $ok('polling from the cursor returns nothing new', count($r->data['messages']) === 0);

    $r = $call('GET', '/messages', [], ['after' => 0], ['x-chat-token' => 'wrong']);
    $ok('wrong token -> 401', $r->status === 401);

    $r = $call('POST', '/messages', ['body' => str_repeat('x', 3000)], [], ['x-chat-token' => $tok]);
    $ok('too-long message -> 422', $r->status === 422);

    $r = $call('GET', '/agent/conversations', [], [], $agent);
    $ok('agent lists open conversations', count($r->data['conversations']) === 1);
    $ok('list carries the newest message as a preview', ($r->data['conversations'][0]['last_message']['body'] ?? '') === 'About $20 and emoji 🙂');

    $call('POST', '/agent/conversations/1/close', [], [], $agent);
    $r = $call('POST', '/messages', ['body' => 'still there?'], [], ['x-chat-token' => $tok]);
    $ok('closed conversation -> 409', $r->status === 409);

    $r = $call('GET', '/agent/conversations', [], ['status' => 'closed'], $agent);
    $ok('closed conversation shows up under status=closed', count($r->data['conversations']) === 1);

    // 6 chats from one IP, the limit is 5 (one was used at the top)
    for ($i = 0; $i < 6; $i++) {
        $r = $call('POST', '/conversations');
    }
    $ok('conversation spam rate-limited -> 429', $r->status === 429);

    echo "\nAll good.\n";
}
