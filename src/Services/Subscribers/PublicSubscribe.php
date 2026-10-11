<?php

declare(strict_types=1);

namespace LaravelMail\Services\Subscribers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\PendingSubscription;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;
use LaravelMail\Models\Tag;
use LaravelMail\Services\MailerFactory;

/**
 * Public signup. Answers are identical for new and existing addresses (no enumeration).
 * Double opt-in confirmation mail is only sent when features.sending is on; otherwise the pending row waits.
 */
class PublicSubscribe
{
    public const SUBSCRIBED = 'subscribed';
    public const PENDING = 'pending_confirmation';
    public const TTL_HOURS = 72;

    public function __construct(private MailerFactory $mailers)
    {
    }

    public function subscribe(Tag $tag, array $input): string
    {
        $email = strtolower(trim($input['email']));
        if (Suppression::isSuppressed($email)) {
            return $tag->double_opt_in ? self::PENDING : self::SUBSCRIBED; // look normal, store nothing
        }
        $existing = Subscriber::where('email', $email)->first();
        $mustConfirm = $tag->double_opt_in || ($existing && $existing->status === 'unsubscribed');

        if ($existing && $existing->isSubscribed() && $existing->tags()->whereKey($tag->id)->exists()) {
            return $tag->double_opt_in ? self::PENDING : self::SUBSCRIBED;
        }
        if ($mustConfirm) {
            $this->startConfirmation($tag, $email, $input);

            return self::PENDING;
        }
        $this->addToList($tag, $email, $input);

        return self::SUBSCRIBED;
    }

    public function confirm(string $token): ?Tag
    {
        $p = PendingSubscription::where('token_hash', hash('sha256', $token))->first();
        if (! $p || $p->confirmed_at || $p->expires_at->isPast()) {
            return null;
        }
        $tag = Tag::find($p->tag_id);
        if (! $tag || ! $tag->acceptsPublicSignups() || Suppression::isSuppressed($p->email)) {
            return null;
        }
        DB::transaction(function () use ($p, $tag) {
            $this->addToList($tag, $p->email, ['first_name' => $p->first_name, 'last_name' => $p->last_name], true);
            $p->forceFill(['confirmed_at' => now()])->save();
        });

        return $tag;
    }

    private function addToList(Tag $tag, string $email, array $input, bool $resubscribe = false): void
    {
        $s = Subscriber::firstOrNew(['email' => $email]);
        $s->fill(array_filter(['first_name' => $input['first_name'] ?? null, 'last_name' => $input['last_name'] ?? null]));
        if ($resubscribe && $s->exists && $s->status === 'unsubscribed') {
            $s->status = 'subscribed';
            $s->unsubscribed_at = null;
        }
        $s->save();
        $s->tags()->syncWithoutDetaching([$tag->id]);
    }

    private function startConfirmation(Tag $tag, string $email, array $input): void
    {
        $token = Str::random(48);
        PendingSubscription::where('email', $email)->where('tag_id', $tag->id)->whereNull('confirmed_at')->delete();
        PendingSubscription::create([
            'tag_id' => $tag->id, 'email' => $email,
            'first_name' => $input['first_name'] ?? null, 'last_name' => $input['last_name'] ?? null,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        if (! config('laravelmail.features.sending')) {
            return; // safe default: no outbound mail until sending is explicitly enabled
        }
        $url = route('laravelmail.subscribe.confirm', ['token' => $token]);
        $this->mailers->make()->to($email)->send(new CampaignMail(
            'Please confirm your subscription',
            '<p>Confirm your subscription to <b>'.e($tag->name).'</b>:</p><p><a href="'.e($url).'">Confirm</a></p><p>If this was not you, ignore this email.</p>',
        ));
    }
}
