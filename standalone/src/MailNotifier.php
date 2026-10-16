<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Contracts\{NotifierInterface, RateLimiterInterface};
use LiveChat\Core\Domain\{Conversation, Message};
use LiveChat\Core\Support\Str;

// Emails the owner. Nothing is sent while the visitor's request is running:
// messages are queued, and api.php calls flush() after the reply has already
// gone out, so a slow mail server never makes the chat feel slow.
//
// driver 'mail' = PHP's mail() (what shared hosting gives you)
// driver 'log'  = append to storage/mail.log (for trying it out on your own
//                 computer, where mail() usually doesn't work)
final class MailNotifier implements NotifierInterface
{
    /** @var array<int, array{to: string, subject: string, body: string, replyTo: ?string}> */
    private array $queue = [];

    public function __construct(
        private string $to,
        private string $from,
        private string $siteName,
        private string $baseUrl,
        private string $driver,
        private string $logFile,
        private RateLimiterInterface $limiter,
    ) {}

    public function newVisitorMessage(Conversation $conversation, Message $message): void
    {
        // One email per conversation every 5 minutes. A chatty visitor
        // shouldn't fill the owner's inbox, the dashboard has the full thread.
        if (!$this->limiter->hit('notify:' . $conversation->id, 1, 300)) {
            return;
        }

        $meta = $conversation->visitorMeta;
        $who = ($meta['name'] ?? '') !== '' ? $meta['name'] : 'A visitor';

        $lines = ["$who wrote:", '', Str::cut($message->body, 500), ''];
        if (!empty($meta['email'])) {
            $lines[] = 'Their email: ' . $meta['email'];
        }
        if (!empty($meta['page_url'])) {
            $lines[] = 'They were on: ' . $meta['page_url'];
        }
        $lines[] = '';
        $lines[] = 'Reply from your dashboard: ' . $this->baseUrl . '/dashboard/';

        $this->queue[] = [
            'to'      => $this->to,
            'subject' => "[{$this->siteName}] New chat message from $who",
            'body'    => implode("\n", $lines),
            'replyTo' => null,
        ];
    }

    public function offlineMessage(string $name, string $email, string $body): void
    {
        $who = $name !== '' ? $name : 'Someone';

        $this->queue[] = [
            'to'      => $this->to,
            'subject' => "[{$this->siteName}] Offline message from $who",
            'body'    => "$who left a message while nobody was online:\n\n$body\n\nReply to them at: $email",
            'replyTo' => $email,
        ];
    }

    public function flush(): void
    {
        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $mail) {
            $this->deliver($mail);
        }
    }

    private function deliver(array $mail): void
    {
        if ($this->driver === 'log') {
            $entry = '--- ' . gmdate('Y-m-d H:i:s') . " UTC\nTo: {$mail['to']}\nSubject: {$mail['subject']}\n\n{$mail['body']}\n\n";
            file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
            return;
        }

        // Header values must never contain line breaks, or whoever controls
        // the value (a visitor's email, say) could add headers of their own.
        $clean = fn (string $v): string => str_replace(["\r", "\n"], '', $v);

        $headers = [
            'From: ' . $clean($this->from),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($mail['replyTo'] !== null) {
            $headers[] = 'Reply-To: ' . $clean($mail['replyTo']);
        }

        $subject = '=?UTF-8?B?' . base64_encode($clean($mail['subject'])) . '?=';
        $body = wordwrap($mail['body'], 76, "\n", true);

        if (!@mail($mail['to'], $subject, $body, implode("\r\n", $headers))) {
            error_log('[livechat] mail() could not send to ' . $mail['to']);
        }
    }
}
