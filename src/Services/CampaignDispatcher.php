<?php

declare(strict_types=1);

namespace LaravelMail\Services;

use LaravelMail\Events\CampaignQueued;
use LaravelMail\Exceptions\InvalidCampaignStateException;
use LaravelMail\Exceptions\SendingDisabledException;
use LaravelMail\Jobs\SendCampaignMessage;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\Message;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;

class CampaignDispatcher
{
    /**
     * Who would receive this campaign. Side-effect free; works while sending is disabled.
     *
     * @return \Illuminate\Support\Collection<int, Subscriber>
     */
    public function preview(Campaign $campaign)
    {
        $suppressed = Suppression::query()->pluck('email')->all();

        return $campaign->audience()->get()
            ->reject(fn (Subscriber $s) => in_array(strtolower($s->email), $suppressed, true))
            ->values();
    }

    /**
     * Queue a draft campaign for delivery. Explicit call only; never invoked by the package itself.
     */
    public function dispatch(Campaign $campaign): int
    {
        if (! config('laravelmail.features.sending')) {
            throw SendingDisabledException::make();
        }
        if (! $campaign->isDraft()) {
            throw new InvalidCampaignStateException("Campaign {$campaign->id} is {$campaign->status}, only drafts can be dispatched.");
        }
        if (blank($campaign->subject) || blank($campaign->content)) {
            throw new InvalidCampaignStateException('Campaign needs a subject and content before dispatch.');
        }

        $recipients = $this->preview($campaign);
        if ($recipients->isEmpty()) {
            throw new InvalidCampaignStateException('Campaign has no eligible recipients.');
        }

        $messageIds = [];
        $ab = app(AbTestService::class);
        $variants = $campaign->variants()->orderBy('id')->get();
        foreach ($recipients as $subscriber) {
            $m = Message::firstOrCreate(
                ['campaign_id' => $campaign->id, 'subscriber_id' => $subscriber->id],
                ['recipient_email' => $subscriber->email, 'subject' => $campaign->subject, 'status' => 'pending',
                    'variant_id' => $ab->assign($campaign, $subscriber->id, $variants)?->id],
            );
            if ($m->status === 'pending') {
                $messageIds[] = $m->id;
            }
        }

        $campaign->forceFill(['status' => Campaign::SENDING])->save();

        foreach ($messageIds as $id) {
            SendCampaignMessage::dispatch($id)
                ->onConnection(config('laravelmail.sending.connection'))
                ->onQueue(config('laravelmail.sending.queue'));
        }

        event(new CampaignQueued($campaign, count($messageIds)));

        return count($messageIds);
    }
}
