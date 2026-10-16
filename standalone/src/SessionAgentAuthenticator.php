<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use LiveChat\Core\Contracts\{AgentAuthenticatorInterface, StorageInterface};
use LiveChat\Core\Domain\Agent;
use LiveChat\Core\Http\Request;
use LiveChat\Core\Support\ChatException;

// "Who is logged in?" answered from the PHP session set up by AuthEndpoint.
final class SessionAgentAuthenticator implements AgentAuthenticatorInterface
{
    public function __construct(private StorageInterface $storage) {}

    public function authenticate(Request $request): ?Agent
    {
        $session = Session::read();
        $id = $session['agent_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }

        // an agent who was deleted while logged in is simply logged out
        $agent = $this->storage->findAgent($id);
        if ($agent === null) {
            return null;
        }

        // A login cookie means another website could get the browser to send
        // requests on the agent's behalf. For anything that changes data we
        // also demand a header that only our own dashboard JavaScript knows.
        if ($request->method !== 'GET') {
            $expected = (string) ($session['csrf'] ?? '');
            $sent = (string) ($request->header('x-csrf-token') ?? '');
            if ($expected === '' || !hash_equals($expected, $sent)) {
                throw new ChatException('csrf_failed', 'Missing or wrong CSRF token.', 403);
            }
        }

        return $agent;
    }
}
