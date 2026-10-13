<?php

declare(strict_types=1);

namespace LiveChat\Core\Contracts;

use LiveChat\Core\Domain\Agent;
use LiveChat\Core\Http\Request;

// Return the logged-in agent, or null. Standalone has its own login,
// Laravel just reuses the app's auth.
interface AgentAuthenticatorInterface
{
    public function authenticate(Request $request): ?Agent;
}
