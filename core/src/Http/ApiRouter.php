<?php
declare(strict_types=1);
namespace LiveChat\Core\Http;

use LiveChat\Core\Contracts\{AgentAuthenticatorInterface, StorageInterface, TransportInterface};
use LiveChat\Core\Domain\{Agent, Conversation, Message};
use LiveChat\Core\Service\ChatService;
use LiveChat\Core\Support\ChatException;

/**
 * The ONE implementation of the JSON API (see docs/api.md).
 * Both wrappers just translate their native request/response into these classes.
 */
final class ApiRouter
{
    public function __construct(
        private ChatService $chat,
        private StorageInterface $storage,
        private TransportInterface $transport,
        private AgentAuthenticatorInterface $auth,
    ) {}

    public function handle(Request $r): Response
    {
        try {
            return $this->dispatch($r);
        } catch (ChatException $e) {
            return new Response($e->httpStatus, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
        }
    }

    private function dispatch(Request $r): Response
    {
        $m = $r->method;
        $p = rtrim($r->path, '/') ?: '/';

        // ---------- visitor endpoints ----------
        if ($m === 'GET' && $p === '/config') {
            return new Response(200, [
                'transport' => $this->transport->clientConfig(),
                'agent_online' => $this->storage->countOnlineAgents() > 0,
            ]);
        }
        if ($m === 'POST' && $p === '/conversations') {
            [$c, $token] = $this->chat->startConversation($r->body, $r->ip);
            return new Response(201, ['conversation_id' => $c->id, 'visitor_token' => $token]);
        }
        if ($m === 'POST' && $p === '/messages') {
            $msg = $this->chat->visitorSend($this->requireToken($r), (string)($r->body['body'] ?? ''), $r->body['client_id'] ?? null, $r->ip);
            return new Response(201, ['message' => $msg->toArray()]);
        }
        if ($m === 'GET' && $p === '/messages') {
            return new Response(200, $this->chat->pollForVisitor($this->requireToken($r), (int)($r->query['after'] ?? 0)));
        }
        if ($m === 'POST' && $p === '/offline') {
            $this->chat->offlineMessage((string)($r->body['name'] ?? ''), (string)($r->body['email'] ?? ''), (string)($r->body['body'] ?? ''), $r->ip);
            return new Response(202, ['ok' => true]);
        }

        // ---------- agent endpoints ----------
        if (str_starts_with($p, '/agent')) {
            $agent = $this->auth->authenticate($r)
                ?? throw new ChatException('unauthenticated', 'Login required.', 401);
            return $this->agentRoutes($agent, $m, substr($p, 6), $r);
        }

        throw new ChatException('not_found', 'Unknown endpoint.', 404);
    }

    private function agentRoutes(Agent $agent, string $m, string $p, Request $r): Response
    {
        if ($m === 'POST' && $p === '/presence') {
            $this->storage->touchAgentPresence($agent->id);
            return new Response(200, ['ok' => true]);
        }
        if ($m === 'GET' && $p === '/events') {   // dashboard's single poll: new messages everywhere
            $after = (int)($r->query['after'] ?? 0);
            $msgs = $this->storage->allMessagesSince($after);
            $this->storage->touchAgentPresence($agent->id);
            return new Response(200, [
                'messages' => array_map(fn (Message $x) => $x->toArray(), $msgs),
                'next_after' => $msgs ? end($msgs)->id : $after,
            ]);
        }
        if ($m === 'GET' && $p === '/conversations') {
            $list = $this->storage->listConversations($r->query['status'] ?? Conversation::OPEN);
            return new Response(200, ['conversations' => array_map(fn (Conversation $c) => $c->toArray(), $list)]);
        }
        if (preg_match('#^/conversations/(\d+)/messages$#', $p, $mm)) {
            $id = (int)$mm[1];
            if ($m === 'GET') {
                $msgs = $this->storage->messagesSince($id, (int)($r->query['after'] ?? 0));
                return new Response(200, ['messages' => array_map(fn (Message $x) => $x->toArray(), $msgs)]);
            }
            if ($m === 'POST') {
                $msg = $this->chat->agentReply($agent, $id, (string)($r->body['body'] ?? ''), $r->body['client_id'] ?? null);
                return new Response(201, ['message' => $msg->toArray()]);
            }
        }
        if ($m === 'POST' && preg_match('#^/conversations/(\d+)/close$#', $p, $mm)) {
            $this->storage->setConversationStatus((int)$mm[1], Conversation::CLOSED);
            return new Response(200, ['ok' => true]);
        }
        throw new ChatException('not_found', 'Unknown endpoint.', 404);
    }

    private function requireToken(Request $r): string
    {
        return $r->visitorToken() ?? throw new ChatException('invalid_token', 'Missing X-Chat-Token header.', 401);
    }
}
