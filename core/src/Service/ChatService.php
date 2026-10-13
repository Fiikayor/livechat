<?php
declare(strict_types=1);
namespace LiveChat\Core\Service;

use LiveChat\Core\Contracts\{NotifierInterface, RateLimiterInterface, StorageInterface, TransportInterface};
use LiveChat\Core\Domain\{Agent, Conversation, Message};
use LiveChat\Core\Support\{ChatException, Str, Token};

/** All business rules live here. No HTTP, no framework, no SQL. */
final class ChatService
{
    public function __construct(
        private StorageInterface $storage,
        private NotifierInterface $notifier,
        private TransportInterface $transport,
        private RateLimiterInterface $limiter,
        private int $maxBodyLength = 2000,
    ) {}

    /** @return array{0: Conversation, 1: string} conversation + PLAIN visitor token (shown once) */
    public function startConversation(array $meta, string $ip): array
    {
        $this->throttle("start:$ip", 5, 600);

        $token = Token::generate();
        $conversation = $this->storage->createConversation(
            Token::hash($token),
            $this->cleanMeta($meta)
        );
        return [$conversation, $token];
    }

    public function visitorSend(string $token, string $body, ?string $clientId, string $ip): Message
    {
        $this->throttle("msg:$ip", 30, 60);
        $conversation = $this->conversationForToken($token);
        if ($conversation->status === Conversation::CLOSED) {
            throw new ChatException('conversation_closed', 'This conversation is closed.', 409);
        }

        if ($clientId !== null && ($dup = $this->storage->findMessageByClientId($conversation->id, $clientId))) {
            return $dup;   // retry: already stored, don't re-broadcast or re-notify
        }
        $message = $this->storage->addMessage(
            $conversation->id, Message::FROM_VISITOR, null, $this->cleanBody($body), $clientId
        );
        $this->transport->publish("conversation.{$conversation->id}", 'message', $message->toArray());
        $this->transport->publish('agents', 'message', $message->toArray());
        $this->notifier->newVisitorMessage($conversation, $message);
        return $message;
    }

    public function agentReply(Agent $agent, int $conversationId, string $body, ?string $clientId): Message
    {
        $conversation = $this->storage->findConversation($conversationId)
            ?? throw new ChatException('not_found', 'Conversation not found.', 404);

        if ($clientId !== null && ($dup = $this->storage->findMessageByClientId($conversation->id, $clientId))) {
            return $dup;
        }
        $message = $this->storage->addMessage(
            $conversation->id, Message::FROM_AGENT, $agent->id, $this->cleanBody($body), $clientId
        );
        $this->transport->publish("conversation.{$conversation->id}", 'message', $message->toArray());
        return $message;
    }

    /** @return array{messages: array, next_after: int, agent_online: bool, status: string} */
    public function pollForVisitor(string $token, int $afterId): array
    {
        $conversation = $this->conversationForToken($token);
        $messages = $this->storage->messagesSince($conversation->id, $afterId);
        $next = $messages ? end($messages)->id : $afterId;

        return [
            'messages' => array_map(fn (Message $m) => $m->toArray(), $messages),
            'next_after' => $next,
            'agent_online' => $this->storage->countOnlineAgents() > 0,
            'status' => $conversation->status,
        ];
    }

    public function offlineMessage(string $name, string $email, string $body, string $ip): void
    {
        $this->throttle("offline:$ip", 3, 3600);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ChatException('invalid_email', 'Please enter a valid email address.', 422);
        }
        $this->notifier->offlineMessage(Str::cut(trim($name), 100), $email, $this->cleanBody($body));
    }

    // ---------- internals ----------
    private function conversationForToken(string $token): Conversation
    {
        return $this->storage->findConversationByTokenHash(Token::hash($token))
            ?? throw new ChatException('invalid_token', 'Unknown conversation.', 401);
    }

    private function cleanBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw new ChatException('empty_message', 'Message cannot be empty.', 422);
        }
        if (Str::len($body) > $this->maxBodyLength) {
            throw new ChatException('message_too_long', "Max {$this->maxBodyLength} characters.", 422);
        }
        return $body;
    }

    private function cleanMeta(array $meta): array
    {
        $allowed = ['name' => 100, 'email' => 150, 'page_url' => 500, 'user_agent' => 300];
        $out = [];
        foreach ($allowed as $key => $max) {
            if (isset($meta[$key]) && is_string($meta[$key])) {
                $out[$key] = Str::cut(trim($meta[$key]), $max);
            }
        }
        return $out;
    }

    private function throttle(string $key, int $max, int $seconds): void
    {
        if (!$this->limiter->hit($key, $max, $seconds)) {
            throw new ChatException('rate_limited', 'Too many requests. Slow down.', 429);
        }
    }
}
