<?php
declare(strict_types=1);
namespace LiveChat\Core\Contracts;

use LiveChat\Core\Domain\Agent;
use LiveChat\Core\Http\Request;

/**
 * Standalone: own login + session/API token.
 * Laravel: wraps Auth::user() / a gate, so devs reuse their existing users.
 */
interface AgentAuthenticatorInterface
{
    public function authenticate(Request $request): ?Agent;
}
