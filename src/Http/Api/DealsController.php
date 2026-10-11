<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Deal;
use LaravelMail\Models\Subscriber;

class DealsController
{
    public function index(Request $r)
    {
        $q = Deal::with('subscriber:id,email')->orderByDesc('id');
        $r->filled('stage') && $q->where('stage', $r->string('stage'));
        $r->filled('subscriber_id') && $q->where('subscriber_id', (int) $r->input('subscriber_id'));

        return $q->paginate(min(max((int) $r->input('per_page', 50), 1), 200))->through(fn ($d) => $this->out($d));
    }

    public function store(Request $r)
    {
        return response()->json($this->out(Deal::create($this->validated($r))->load('subscriber:id,email')), 201);
    }

    public function show(int $id)
    {
        return $this->out(Deal::with('subscriber:id,email')->findOrFail($id));
    }

    public function update(Request $r, int $id)
    {
        $d = Deal::findOrFail($id);
        $d->update($this->validated($r, true));

        return $this->out($d->load('subscriber:id,email'));
    }

    public function destroy(int $id)
    {
        Deal::findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Totals per stage: count and value. */
    public function summary()
    {
        return Deal::query()->selectRaw('stage, count(*) as deals, sum(value) as value')->groupBy('stage')->get()
            ->map(fn ($r) => ['stage' => $r->stage, 'deals' => (int) $r->deals, 'value' => round((float) $r->value, 2)]);
    }

    private function validated(Request $r, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $d = $r->validate([
            'title' => "$req|string|max:255", 'value' => 'nullable|numeric|min:0|max:9999999999', 'currency' => 'nullable|string|size:3|alpha',
            'stage' => 'nullable|in:'.implode(',', array_keys(Deal::STAGES)), 'expected_close_on' => 'nullable|date', 'notes' => 'nullable|string|max:5000',
            'subscriber_id' => 'nullable|integer',
        ]);
        if (! empty($d['subscriber_id'])) {
            abort_unless(Subscriber::whereKey($d['subscriber_id'])->exists(), 422, 'Unknown subscriber.');
        }

        return array_filter($d, fn ($v) => $v !== null);
    }

    private function out(Deal $d): array
    {
        return ['id' => $d->id, 'title' => $d->title, 'value' => (float) $d->value, 'currency' => $d->currency, 'stage' => $d->stage, 'subscriber_id' => $d->subscriber_id,
            'subscriber_email' => $d->subscriber?->email, 'expected_close_on' => $d->expected_close_on?->toDateString(), 'notes' => $d->notes,
            'closed_at' => $d->closed_at?->toIso8601String(), 'created_at' => $d->created_at?->toIso8601String()];
    }
}
