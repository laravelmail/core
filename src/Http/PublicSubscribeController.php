<?php

declare(strict_types=1);

namespace LaravelMail\Http;

use Illuminate\Http\Request;
use LaravelMail\Models\Tag;
use LaravelMail\Services\Subscribers\PublicSubscribe;

class PublicSubscribeController
{
    public const HONEYPOT = 'website';

    public function subscribe(Request $request, string $uuid, PublicSubscribe $service)
    {
        $tag = Tag::withoutGlobalScopes()->where('public_uuid', $uuid)->where('public_subscribe_enabled', true)->first();
        abort_unless($tag, 404);
        abort_unless($this->originAllowed($tag, $request->headers->get('Origin')), 403);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Bots filling the honeypot get a normal answer and nothing is stored.
        $status = filled($request->input(self::HONEYPOT))
            ? ($tag->double_opt_in ? PublicSubscribe::PENDING : PublicSubscribe::SUBSCRIBED)
            : $this->withTagWorkspace($tag, fn () => $service->subscribe($tag, $data));

        return response()->json(['status' => $status], $status === PublicSubscribe::PENDING ? 202 : 200);
    }

    public function confirm(string $token, PublicSubscribe $service)
    {
        $tag = $service->confirm($token);
        abort_unless($tag, 404);

        return response()->json(['status' => 'subscribed']);
    }

    /** Runs with the tag's workspace bound so created rows land in the right tenant. */
    private function withTagWorkspace(Tag $tag, callable $fn)
    {
        $previous = app(\LaravelMail\Contracts\WorkspaceResolver::class);
        app()->instance(\LaravelMail\Contracts\WorkspaceResolver::class, new class($tag->workspace_id) implements \LaravelMail\Contracts\WorkspaceResolver {
            public function __construct(private ?string $id) {}
            public function currentId(): int|string|null { return $this->id; }
        });
        try {
            return $fn();
        } finally {
            app()->instance(\LaravelMail\Contracts\WorkspaceResolver::class, $previous);
        }
    }

    private function originAllowed(Tag $tag, ?string $origin): bool
    {
        $allowed = array_filter($tag->allowed_origins ?? []);
        if ($allowed === [] || $origin === null) {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        foreach ($allowed as $entry) {
            $entry = strtolower(trim($entry));
            $eh = parse_url(str_contains($entry, '://') ? $entry : 'https://'.$entry, PHP_URL_HOST);
            if ($eh && ($host === $eh || (str_starts_with($eh, '*.') && str_ends_with((string) $host, substr($eh, 1))))) {
                return true;
            }
        }

        return false;
    }
}
