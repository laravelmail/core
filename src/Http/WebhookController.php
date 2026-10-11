<?php

declare(strict_types=1);

namespace LaravelMail\Http;

use Illuminate\Http\Request;
use LaravelMail\Services\Webhooks\BounceHandler;
use LaravelMail\Services\Webhooks\WebhookAuth;

class WebhookController
{
    public function __invoke(Request $request, string $provider, WebhookAuth $auth, BounceHandler $handler)
    {
        abort_unless(in_array($provider, ['mailgun', 'postmark'], true), 404);

        $secret = $auth->secret($provider);
        $payload = $request->json()->all() ?: $request->all();

        $ok = $auth->allowUnsigned() || ($secret !== null && match ($provider) {
            'mailgun' => $auth->mailgun($payload, $secret),
            'postmark' => $auth->token($request, $secret),
        });
        abort_unless($ok, 403);

        [$email, $type] = match ($provider) {
            'mailgun' => $this->mailgun($payload),
            'postmark' => $this->postmark($payload),
        };
        if ($email && $type) {
            $handler->handle($email, $type, $payload['hash'] ?? ($payload['Metadata']['laravelmail_message'] ?? null));
        }

        return response()->json(['ok' => true]);
    }

    private function mailgun(array $p): array
    {
        $e = $p['event-data'] ?? [];
        $event = $e['event'] ?? null;
        $type = match (true) {
            $event === 'complained' => BounceHandler::COMPLAINT,
            $event === 'failed' && ($e['severity'] ?? '') === 'permanent' => BounceHandler::HARD_BOUNCE,
            default => null,
        };

        return [$e['recipient'] ?? null, $type];
    }

    private function postmark(array $p): array
    {
        $type = match ($p['RecordType'] ?? null) {
            'SpamComplaint' => BounceHandler::COMPLAINT,
            'Bounce' => ($p['Type'] ?? '') === 'HardBounce' ? BounceHandler::HARD_BOUNCE : null,
            default => null,
        };

        return [$p['Email'] ?? $p['Recipient'] ?? null, $type];
    }
}
