<?php
declare(strict_types=1);

namespace LiveChat\Core\Contracts;

use LiveChat\Core\Domain\{Conversation, Message};

// Email, push, whatever. Don't block the request while sending.
interface NotifierInterface
{
    public function newVisitorMessage(Conversation $conversation, Message $message): void;
    public function offlineMessage(string $name, string $email, string $body): void;
}