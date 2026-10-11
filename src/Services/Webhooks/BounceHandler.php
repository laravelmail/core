<?php

declare(strict_types=1);

namespace LaravelMail\Services\Webhooks;

use LaravelMail\Models\Message;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;

/** Applies a normalised bounce/complaint event: suppress the address and unsubscribe matching subscribers. */
class BounceHandler
{
    public const HARD_BOUNCE = 'hard_bounce';
    public const COMPLAINT = 'complaint';

    public function handle(string $email, string $type, ?string $messageHash = null): void
    {
        if (! in_array($type, [self::HARD_BOUNCE, self::COMPLAINT], true)) {
            return; // soft bounces and unknown events never suppress
        }
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        Suppression::withoutGlobalScopes()->firstOrCreate(
            ['workspace_id' => $this->workspaceFor($email, $messageHash), 'email' => $email],
            ['reason' => $type],
        );
        Subscriber::withoutGlobalScopes()->where('email', $email)->get()->each->unsubscribe();

        if ($messageHash && ($m = Message::withoutGlobalScopes()->where('hash', $messageHash)->first())) {
            $m->update(['status' => 'failed', 'error' => $type]);
        }
    }

    private function workspaceFor(string $email, ?string $hash): ?string
    {
        if ($hash && ($m = Message::withoutGlobalScopes()->where('hash', $hash)->first())) {
            return $m->workspace_id;
        }
        $s = Subscriber::withoutGlobalScopes()->where('email', $email)->first();

        return $s?->workspace_id;
    }
}
