<?php

declare(strict_types=1);

namespace LaravelMail\Services\Automations;

use Illuminate\Support\Facades\Http;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\Automation;
use LaravelMail\Models\AutomationJourney;
use LaravelMail\Models\AutomationStep;
use LaravelMail\Models\Segment;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;
use LaravelMail\Models\Tag;
use LaravelMail\Services\MailerFactory;
use LaravelMail\Services\Renderer;
use LaravelMail\Services\Segments\SegmentRuleCompiler;
use Throwable;

/**
 * Runs automation journeys. Nothing happens unless features.automations is on, the automation is
 * active, and you call enrol()/tick() yourself (or schedule laravelmail:automation-run via LaravelMail::schedule()).
 * send_email additionally needs features.sending; with sending off a journey parks at that step.
 */
class FlowEngine
{
    public const MAX_STEPS_PER_TICK = 50;

    public function __construct(private MailerFactory $mailers, private Renderer $renderer, private SegmentRuleCompiler $segments)
    {
    }

    public function enrol(Automation $automation, Subscriber $subscriber): ?AutomationJourney
    {
        $this->assertEnabled();
        if (! $automation->active || ! $subscriber->isSubscribed() || Suppression::isSuppressed($subscriber->email)) {
            return null;
        }
        $start = $automation->steps()->where('is_start', true)->first() ?? $automation->steps()->orderBy('id')->first();
        if (! $start) {
            return null;
        }

        return AutomationJourney::firstOrCreate(
            ['automation_id' => $automation->id, 'subscriber_id' => $subscriber->id],
            ['current_key' => $start->node_key, 'status' => 'active', 'wake_at' => now()],
        );
    }

    /** Process due journeys. Returns the number of journeys touched. */
    public function tick(?Automation $only = null): int
    {
        $this->assertEnabled();
        $q = AutomationJourney::query()->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('wake_at')->orWhere('wake_at', '<=', now()))
            ->whereHas('automation', fn ($a) => $a->withoutGlobalScopes()->where('active', true));
        if ($only) {
            $q->where('automation_id', $only->id);
        }

        $n = 0;
        $q->orderBy('id')->each(function (AutomationJourney $j) use (&$n) {
            $this->run($j);
            $n++;
        });

        return $n;
    }

    public function run(AutomationJourney $journey): void
    {
        $this->assertEnabled();
        $subscriber = $journey->subscriber;
        $steps = AutomationStep::where('automation_id', $journey->automation_id)->get()->keyBy('node_key');

        if (! $subscriber || ! $subscriber->isSubscribed() || Suppression::isSuppressed($subscriber->email)) {
            $this->finish($journey, 'exited', 'subscriber unsubscribed or suppressed');

            return;
        }
        foreach ($steps->where('type', FlowGraphValidator::TYPE_GOAL) as $goal) {
            if ($this->check($subscriber, (array) $goal->config)) {
                $this->finish($journey, 'completed', 'goal reached: '.$goal->node_key);

                return;
            }
        }

        for ($i = 0; $i < self::MAX_STEPS_PER_TICK; $i++) {
            $step = $steps->get((string) $journey->current_key);
            if (! $step) {
                $this->finish($journey, 'completed', 'end of flow');

                return;
            }

            $outcome = $this->execute($step, $subscriber, $journey);
            if ($outcome === 'park') {
                return;
            }
            if ($outcome === 'stop') {
                $this->finish($journey, 'exited', 'exit step');

                return;
            }
            if ($outcome === 'failed') {
                $this->finish($journey, 'failed', 'step failed: '.$step->node_key);

                return;
            }

            $next = $outcome === 'alt' ? $step->alt_key : $step->next_key;
            $journey->forceFill(['current_key' => $next])->save();
            if ($outcome === 'wait') {
                return;
            }
            if ($next === null) {
                $this->finish($journey, 'completed', 'end of flow');

                return;
            }
        }

        // Step budget exhausted: park rather than spin.
        $journey->forceFill(['wake_at' => now()->addMinutes(5)])->save();
    }

    /** @return 'next'|'alt'|'wait'|'park'|'stop'|'failed' */
    private function execute(AutomationStep $step, Subscriber $s, AutomationJourney $journey): string
    {
        $c = (array) $step->config;

        switch ($step->type) {
            case FlowGraphValidator::TYPE_WAIT:
                $mins = (int) ($c['minutes'] ?? 0) + 60 * (int) ($c['hours'] ?? 0) + 1440 * (int) ($c['days'] ?? 0);
                $journey->forceFill(['wake_at' => now()->addMinutes(max(1, $mins))])->save();

                return 'wait';

            case FlowGraphValidator::TYPE_CONDITION:
                return $this->check($s, $c) ? 'next' : 'alt';

            case FlowGraphValidator::TYPE_WAIT_UNTIL:
                if ($this->check($s, $c)) {
                    return 'next';
                }
                $deadline = $journey->created_at->copy()->addHours((int) ($c['timeout_hours'] ?? 24));
                if (now()->greaterThanOrEqualTo($deadline)) {
                    return 'alt';
                }
                $journey->forceFill(['wake_at' => now()->addMinutes(15)])->save();

                return 'park';

            case FlowGraphValidator::TYPE_ADD_TAG:
                $s->tags()->syncWithoutDetaching([(int) $c['tag_id']]);

                return 'next';

            case FlowGraphValidator::TYPE_REMOVE_TAG:
                $s->tags()->detach((int) $c['tag_id']);

                return 'next';

            case FlowGraphValidator::TYPE_UPDATE_FIELD:
                if (preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) ($c['field'] ?? ''))) {
                    $meta = (array) $s->meta;
                    $meta[$c['field']] = $c['value'] ?? null;
                    $s->forceFill(['meta' => $meta])->save();
                }

                return 'next';

            case FlowGraphValidator::TYPE_WEBHOOK:
                return $this->webhook($c, $s) ? 'next' : 'failed';

            case FlowGraphValidator::TYPE_SEND_EMAIL:
                if (! config('laravelmail.features.sending')) {
                    $journey->forceFill(['wake_at' => now()->addHour(), 'log' => $this->log($journey, 'parked: sending is disabled')])->save();

                    return 'park';
                }

                return $this->send($c, $s, $journey) ? 'next' : 'failed';

            case FlowGraphValidator::TYPE_SEND_SMS:
                if (! config('laravelmail.features.sms')) {
                    $journey->forceFill(['wake_at' => now()->addHour(), 'log' => $this->log($journey, 'parked: sms is disabled')])->save();

                    return 'park';
                }
                $sms = app(\LaravelMail\Services\Sms\SmsSender::class)->send($s, (string) ($c['body'] ?? ''));
                if ($sms->status !== 'sent') {
                    $journey->forceFill(['log' => $this->log($journey, "sms {$sms->status}: {$sms->error}")])->save();
                }

                return $sms->status === 'sent' ? 'next' : 'failed';

            case FlowGraphValidator::TYPE_EXIT:
                return 'stop';
        }

        return 'failed'; // unknown or unsupported step type (e.g. send_sms in this slice): never skipped silently
    }

    public function check(Subscriber $s, array $c): bool
    {
        $v = $c['value'] ?? null;

        return match ($c['check'] ?? null) {
            'has_tag' => $s->tags()->whereKey((int) $v)->exists(),
            'lead_score' => (int) $s->fresh()->score >= (int) $v,
            'in_segment' => ($seg = Segment::find((int) $v))
                && $this->segments->query((array) $seg->rules)->whereKey($s->id)->exists(),
            'field' => $this->field($s, (string) ($c['field'] ?? ''), (string) ($c['operator'] ?? 'equals'), $v),
            default => false,
        };
    }

    private function field(Subscriber $s, string $field, string $op, mixed $v): bool
    {
        $actual = in_array($field, ['email', 'first_name', 'last_name'], true) ? $s->{$field} : data_get((array) $s->meta, $field);
        $a = mb_strtolower((string) $actual);
        $b = mb_strtolower((string) $v);

        return match ($op) {
            'equals' => $a === $b,
            'not_equals' => $a !== $b,
            'contains' => $b !== '' && str_contains($a, $b),
            'is_empty' => $a === '',
            'is_not_empty' => $a !== '',
            default => false,
        };
    }

    private function send(array $c, Subscriber $s, AutomationJourney $journey): bool
    {
        $subject = (string) ($c['subject'] ?? '');
        $html = (string) ($c['content'] ?? '');
        if ($subject === '' || $html === '') {
            return false;
        }
        try {
            $this->mailers->make($journey->id)->to($s->email)->send(new CampaignMail(
                $this->renderer->merge($subject, $s, false),
                $this->renderer->merge($html, $s),
                $c['from_email'] ?? null,
                $c['from_name'] ?? null,
            ));
            $journey->forceFill(['log' => $this->log($journey, 'sent: '.$subject)])->save();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function webhook(array $c, Subscriber $s): bool
    {
        $url = (string) ($c['url'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        if (! str_starts_with($url, 'https://') || ! is_string($host) || $this->privateHost($host)) {
            return false; // https only, never internal addresses (SSRF)
        }
        try {
            return Http::timeout(5)->withoutRedirecting()->post($url, ['email' => $s->email, 'subscriber_id' => $s->id])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function privateHost(string $host): bool
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return true;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }

    private function finish(AutomationJourney $j, string $status, string $why): void
    {
        $j->forceFill(['status' => $status, 'wake_at' => null, 'log' => $this->log($j, "{$status}: {$why}")])->save();
    }

    private function log(AutomationJourney $j, string $line): array
    {
        $log = (array) $j->log;
        $log[] = now()->toDateTimeString().' '.$line;

        return array_slice($log, -50);
    }

    private function assertEnabled(): void
    {
        if (! config('laravelmail.features.automations')) {
            throw new \LaravelMail\Exceptions\FeatureDisabledException('automations');
        }
    }
}
