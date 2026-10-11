<?php

declare(strict_types=1);

namespace LaravelMail\Services\Scoring;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LaravelMail\Models\ScoreEvent;
use LaravelMail\Models\ScoringRule;
use LaravelMail\Models\Subscriber;

/**
 * Lead scoring. Rules award points for events; every award is a ledger row and the running total lives on
 * subscribers.score. An award is idempotent per rule + subscriber + reference (so a re-opened email or a
 * replayed webhook never double counts). Every entry point is a no-op while features.scoring is off.
 */
class ScoringService
{
    public function enabled(): bool
    {
        return (bool) config('laravelmail.features.scoring');
    }

    /** @return int points awarded in total across matching rules */
    public function record(Subscriber $s, string $event, ?string $reference = null, ?int $tagId = null, ?string $custom = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $q = ScoringRule::where('active', true)->where('event', $event);
        $event === 'tag_added' && $q->where(fn ($w) => $w->whereNull('tag_id')->orWhere('tag_id', $tagId));
        $event === 'custom' && $q->where('custom_event', $custom);

        $total = 0;
        foreach ($q->get() as $rule) {
            $total += $this->award($s, $rule, $reference);
        }

        return $total;
    }

    private function award(Subscriber $s, ScoringRule $rule, ?string $reference): int
    {
        $points = (int) $rule->points;
        if ($rule->max_total !== null) {
            $given = (int) ScoreEvent::where('rule_id', $rule->id)->where('subscriber_id', $s->id)->sum('points');
            $room = $points >= 0 ? $rule->max_total - $given : -($rule->max_total + $given); // caps work on either sign
            $points = $points >= 0 ? min($points, max(0, $room)) : max($points, min(0, $room));
        }
        if ($points === 0) {
            return 0;
        }
        try {
            DB::transaction(function () use ($s, $rule, $reference, $points) {
                ScoreEvent::create(['subscriber_id' => $s->id, 'workspace_id' => $s->workspace_id, 'rule_id' => $rule->id,
                    'event' => $rule->event, 'reference' => $reference, 'points' => $points, 'note' => $rule->name]);
                Subscriber::withoutGlobalScopes()->whereKey($s->id)->increment('score', $points);
            });
        } catch (UniqueConstraintViolationException) {
            return 0; // already awarded for this reference
        }

        return $points;
    }

    /** Manual adjustment (positive or negative). Recorded as a ledger row with a note. */
    public function adjust(Subscriber $s, int $points, string $note): void
    {
        DB::transaction(function () use ($s, $points, $note) {
            ScoreEvent::create(['subscriber_id' => $s->id, 'workspace_id' => $s->workspace_id, 'event' => 'manual', 'points' => $points, 'note' => mb_substr($note, 0, 255)]);
            Subscriber::withoutGlobalScopes()->whereKey($s->id)->increment('score', $points);
        });
    }

    /** Take $points off positive scores of people with no score activity for $days. Never goes below zero. @return int subscribers decayed */
    public function decay(int $days, int $points): int
    {
        if (! $this->enabled() || $days < 1 || $points < 1) {
            return 0;
        }
        $cut = now()->subDays($days);
        $n = 0;
        Subscriber::where('score', '>', 0)
            ->whereNotExists(fn ($q) => $q->from((new ScoreEvent)->getTable())->whereColumn('subscriber_id', (new Subscriber)->getTable().'.id')->where('created_at', '>=', $cut))
            ->each(function (Subscriber $s) use ($points, &$n) {
                $this->adjust($s, -min($points, $s->score), 'inactivity decay');
                $n++;
            });

        return $n;
    }
}
