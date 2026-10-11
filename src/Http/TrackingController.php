<?php

declare(strict_types=1);

namespace LaravelMail\Http;

use LaravelMail\Models\Message;
use LaravelMail\Models\MessageUrl;

class TrackingController
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $message)
    {
        $m = Message::withoutGlobalScopes()->where('hash', $message)->first();
        if ($m) {
            $first = $m->opened_at === null;
            $m->forceFill(['opened_at' => $m->opened_at ?? now(), 'open_count' => $m->open_count + 1])->save();
            // Points once per message: repeat loads and mail-client prefetch never stack.
            $first && $this->score($m, 'email_opened', 'open:'.$m->id);
        }

        return response(base64_decode(self::GIF), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    public function click(string $message, string $url)
    {
        $m = Message::withoutGlobalScopes()->where('hash', $message)->first();
        $u = $m ? MessageUrl::where('message_id', $m->id)->where('hash', $url)->first() : null;
        abort_unless($u, 404);

        // Only ever redirect to a URL we stored ourselves, and only http(s): no open redirect.
        abort_unless(preg_match('#^https?://#i', $u->url) === 1, 404);
        $first = $u->click_count === 0;
        $u->increment('click_count');
        $m->forceFill(['clicked_at' => $m->clicked_at ?? now(), 'click_count' => $m->click_count + 1])->save();

        $first && $this->score($m, 'link_clicked', 'click:'.$u->id);

        return redirect()->away($u->url);
    }

    private function score(Message $m, string $event, string $ref): void
    {
        if (config('laravelmail.features.scoring') && ($s = \LaravelMail\Models\Subscriber::withoutGlobalScopes()->find($m->subscriber_id))) {
            app(\LaravelMail\Services\Scoring\ScoringService::class)->record($s, $event, $ref);
        }
    }
}
