<?php
declare(strict_types=1);
namespace LiveChat\Core\Contracts;

use LiveChat\Core\Domain\{Conversation, Message};

/**
 * Standalone: mail() / PHPMailer, optional Web Push.
 * Laravel: Notification + Mail + queues.
 * Implementations SHOULD NOT block the request (queue or fire-and-forget).
 */
interface NotifierInterface
{
    public function newVisitorMessage(Conversation $conversation, Message $message): void;
    public function offlineMessage(string $name, string $email, string $body): void;
}
