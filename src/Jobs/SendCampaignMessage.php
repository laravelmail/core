<?php

declare(strict_types=1);

namespace LaravelMail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\Message;
use LaravelMail\Models\Suppression;
use LaravelMail\Services\Renderer;
use Throwable;

class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
    }

    public function handle(Renderer $renderer): void
    {
        // Safety gate is re-checked at send time, not just at queue time.
        if (! config('laravelmail.features.sending')) {
            return;
        }

        $message = Message::withoutGlobalScopes()->with(['campaign.template', 'subscriber'])->find($this->messageId);
        if (! $message || $message->status !== 'pending') {
            return;
        }

        $subscriber = $message->subscriber;
        if (! $subscriber->isSubscribed() || Suppression::withoutGlobalScopes()->where('email', strtolower($subscriber->email))
            ->where('workspace_id', $subscriber->workspace_id)->exists()) {
            $message->update(['status' => 'failed', 'error' => 'suppressed or unsubscribed']);
            $this->finish($message->campaign);

            return;
        }

        $campaign = $message->campaign;
        try {
            $this->mailer()
                ->to($subscriber->email)
                ->send(new CampaignMail(
                    $renderer->renderSubject($campaign, $subscriber),
                    $renderer->render($campaign, $subscriber),
                    $campaign->from_email,
                    $campaign->from_name,
                ));
            $message->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $e) {
            $message->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        $this->finish($campaign);
    }

    /** Package-owned mailer: an inline transport array (Mail::build) or a named host mailer. Never rewrites host mail config. */
    private function mailer()
    {
        $m = config('laravelmail.sending.mailer');

        return is_array($m) ? Mail::build($m) : Mail::mailer($m);
    }

    private function finish(Campaign $campaign): void
    {
        if (! Message::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('status', 'pending')->exists()) {
            $campaign->forceFill(['status' => Campaign::SENT, 'sent_at' => now()])->save();
        }
    }
}
