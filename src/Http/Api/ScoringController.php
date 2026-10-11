<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\ScoreEvent;
use LaravelMail\Models\ScoringRule;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\Scoring\ScoringService;

class ScoringController
{
    public function index()
    {
        return ScoringRule::orderBy('id')->get();
    }

    public function store(Request $r)
    {
        return response()->json(ScoringRule::create($this->validated($r)), 201);
    }

    public function update(Request $r, int $id)
    {
        $rule = ScoringRule::findOrFail($id);
        $rule->update($this->validated($r));

        return $rule;
    }

    public function destroy(int $id)
    {
        ScoringRule::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function events(int $id)
    {
        return ScoreEvent::where('subscriber_id', Subscriber::findOrFail($id)->id)->orderByDesc('id')->limit(100)->get();
    }

    /** Record a named custom event for a subscriber (awards points per matching custom rules). */
    public function custom(Request $r, int $id, ScoringService $svc)
    {
        $d = $r->validate(['event' => 'required|string|max:50', 'reference' => 'nullable|string|max:100']);
        $s = Subscriber::findOrFail($id);

        return ['awarded' => $svc->record($s, 'custom', $d['reference'] ?? null, custom: $d['event']), 'score' => $s->fresh()->score];
    }

    private function validated(Request $r): array
    {
        $d = $r->validate([
            'name' => 'required|string|max:255', 'event' => 'required|in:'.implode(',', ScoringRule::EVENTS), 'points' => 'required|integer|between:-1000,1000|not_in:0',
            'tag_id' => 'nullable|integer|exists:'.(new \LaravelMail\Models\Tag)->getTable().',id', 'custom_event' => 'nullable|string|max:50',
            'max_total' => 'nullable|integer|min:1|max:100000', 'active' => 'boolean',
        ]);
        if ($d['event'] === 'custom' && blank($d['custom_event'] ?? null)) {
            abort(422, 'custom rules need a custom_event name.');
        }

        return $d;
    }
}
