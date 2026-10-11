<?php

declare(strict_types=1);

namespace LaravelMail\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelMail\Contracts\WorkspaceResolver;
use LaravelMail\Models\ApiToken;

/** Bearer token auth. Binds the token's workspace for the request so every query is tenant-scoped. Usage: laravelmail.token:ability */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $ability = '')
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : ApiToken::where('token_hash', hash('sha256', $plain))->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($ability !== '' && ! $token->can($ability)) {
            return response()->json(['message' => 'This token cannot do that.'], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $ws = $token->workspace_id;
        app()->instance(WorkspaceResolver::class, new class($ws) implements WorkspaceResolver {
            public function __construct(private ?string $id) {}
            public function currentId(): int|string|null { return $this->id; }
        });
        $request->attributes->set('laravelmail_token', $token);

        return $next($request);
    }
}
