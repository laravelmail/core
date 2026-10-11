<?php

declare(strict_types=1);

namespace LaravelMail\Services\Conversations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LaravelMail\Exceptions\FeatureDisabledException;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\Conversation;
use LaravelMail\Models\ConversationMessage;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;

/**
 * One-to-one threads with a subscriber. Recording a message and emailing it are separate steps:
 * reply() only records; deliver() emails, and only with features.sending on and a subscribed, unsuppressed recipient.
 */
class ConversationService
{
    public function open(Subscriber $s, string $subject, string $body, int|string|null $userId = null): Conversation
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($s, $subject, $body, $userId) {
            $c = Conversation::create(['subscriber_id' => $s->id, 'subject' => mb_substr($subject, 0, 255), 'created_by' => $userId === null ? null : (string) $userId]);
            $this->add($c, 'user', $body, $userId === null ? null : (string) $userId);

            return $c;
        });
    }

    /** A person at the host (agent) answered. Records only; nothing is emailed. */
    public function reply(Conversation $c, string $body, int|string|null $userId = null): ConversationMessage
    {
        $this->assertEnabled();

        return $this->add($c, 'user', $body, $userId === null ? null : (string) $userId);
    }

    /** An inbound reply from the subscriber (host webhook calls this). Idempotent on $providerMessageId; reopens a closed thread. */
    public function recordInbound(Conversation $c, string $body, ?string $providerMessageId = null): ?ConversationMessage
    {
        $this->assertEnabled();
        if ($providerMessageId !== null && $c->messages()->where('provider_message_id', $providerMessageId)->exists()) {
            return null;
        }
        $m = $this->add($c, 'subscriber', $body, null, $providerMessageId);
        $c->forceFill(['status' => 'open'])->save();

        return $m;
    }

    public function close(Conversation $c): void
    {
        $c->forceFill(['status' => 'closed'])->save();
    }

    /** Email a recorded user message to the subscriber. @return bool true when sent */
    public function deliver(ConversationMessage $m): bool
    {
        $this->assertEnabled();
        if (! config('laravelmail.features.sending') || $m->sender_type !== 'user' || $m->sent_at !== null) {
            return false;
        }
        $c = $m->conversation;
        $s = $c->subscriber;
        if (! $s || ! $s->isSubscribed() || Suppression::isSuppressed($s->email)) {
            return false;
        }
        Mail::to($s->email)->send(new CampaignMail('Re: '.$c->subject, nl2br(e($m->body)), null, null));
        $m->forceFill(['sent_at' => now()])->save();

        return true;
    }

    private function add(Conversation $c, string $type, string $body, ?string $senderId, ?string $providerId = null): ConversationMessage
    {
        $m = $c->messages()->create(['workspace_id' => $c->workspace_id, 'sender_type' => $type, 'sender_id' => $senderId, 'body' => $body, 'provider_message_id' => $providerId]);
        $c->forceFill(['last_reply_at' => now()])->save();

        return $m;
    }

    private function assertEnabled(): void
    {
        if (! config('laravelmail.features.conversations')) {
            throw new FeatureDisabledException('conversations');
        }
    }
}
