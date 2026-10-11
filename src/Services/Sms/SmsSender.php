<?php

declare(strict_types=1);

namespace LaravelMail\Services\Sms;

use LaravelMail\Contracts\SmsDriver;
use LaravelMail\Exceptions\FeatureDisabledException;
use LaravelMail\Models\SmsMessage;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\Renderer;
use LaravelMail\Support\PhoneNumber;

/**
 * Sends a text to one subscriber. Every message is recorded. A text is only sent when the person has a valid
 * E.164 number, explicit SMS consent, has not opted out, is still subscribed and is not suppressed.
 */
class SmsSender
{
    public function __construct(private Renderer $renderer)
    {
    }

    public function driver(): SmsDriver
    {
        $name = config('laravelmail.sms.driver', 'log');
        $class = config("laravelmail.sms.drivers.$name") ?? match ($name) {
            'log' => LogDriver::class,
            'null' => NullDriver::class,
            default => throw new \InvalidArgumentException("Unknown SMS driver [$name]. Register it under laravelmail.sms.drivers."),
        };

        return app($class);
    }

    public function skipReason(Subscriber $s): ?string
    {
        return match (true) {
            ! PhoneNumber::isValid($s->phone) => 'no valid phone number',
            $s->sms_consent_at === null => 'no SMS consent',
            $s->sms_opted_out_at !== null => 'opted out of SMS',
            ! $s->isSubscribed() => 'unsubscribed',
            \LaravelMail\Models\Suppression::isSuppressed($s->email) => 'suppressed',
            default => null,
        };
    }

    public function send(Subscriber $s, string $template): SmsMessage
    {
        if (! config('laravelmail.features.sms')) {
            throw new FeatureDisabledException('sms');
        }
        $body = trim($this->renderer->merge($template, $s, false));
        $max = (int) config('laravelmail.sms.max_length', 1000);
        $m = SmsMessage::create(['subscriber_id' => $s->id, 'to' => (string) PhoneNumber::normalize($s->phone), 'body' => mb_substr($body, 0, $max)]);

        if ($body === '') {
            return $this->finish($m, 'skipped', error: 'empty message');
        }
        if ($reason = $this->skipReason($s)) {
            return $this->finish($m, 'skipped', error: $reason);
        }
        if (config('laravelmail.sms.append_opt_out') && ! str_contains(strtolower($m->body), 'stop')) {
            $m->body = mb_substr($m->body, 0, $max - 30).' '.config('laravelmail.sms.opt_out_text', 'Reply STOP to opt out.');
        }

        try {
            $d = $this->driver();
            $id = $d->send($m->to, $m->body);
        } catch (\Throwable $e) {
            return $this->finish($m, 'failed', error: mb_substr($e->getMessage(), 0, 500));
        }

        return $this->finish($m, 'sent', $d->name(), $id);
    }

    /** Handle an inbound text from a provider webhook you own. STOP-style words opt the number out; START opts back in only if consent exists. */
    public function handleInbound(string $from, string $text): ?string
    {
        $phone = PhoneNumber::normalize($from);
        $word = strtoupper(trim($text));
        $stop = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
        if (! PhoneNumber::isValid($phone) || ! in_array($word, $stop, true)) {
            return null;
        }
        $n = Subscriber::where('phone', $phone)->whereNull('sms_opted_out_at')->update(['sms_opted_out_at' => now()]);

        return $n > 0 ? 'opted_out' : 'already_opted_out_or_unknown';
    }

    private function finish(SmsMessage $m, string $status, ?string $driver = null, ?string $providerId = null, ?string $error = null): SmsMessage
    {
        $m->fill(['status' => $status, 'driver' => $driver, 'provider_id' => $providerId, 'error' => $error, 'sent_at' => $status === 'sent' ? now() : null])->save();

        return $m;
    }
}
