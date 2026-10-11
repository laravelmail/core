<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Exceptions\InvalidCampaignStateException;
use LaravelMail\Exceptions\SendingDisabledException;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\Tag;
use LaravelMail\Services\CampaignDispatcher;

class CampaignsController
{
    public function index(Request $r)
    {
        return Campaign::orderByDesc('id')->paginate(min(max((int) $r->input('per_page', 50), 1), 200))->through(fn ($c) => $this->out($c));
    }

    public function store(Request $r)
    {
        $c = Campaign::create($this->validated($r) + ['status' => Campaign::DRAFT]); // API-created campaigns are always drafts
        $this->sync($c, $r);

        return response()->json($this->out($c->fresh()), 201);
    }

    public function show(int $id)
    {
        return $this->out(Campaign::findOrFail($id));
    }

    public function update(Request $r, int $id)
    {
        $c = Campaign::findOrFail($id);
        abort_unless($c->isDraft(), 409, 'Only draft campaigns can be edited.');
        $c->update($this->validated($r, false));
        $this->sync($c, $r);

        return $this->out($c->fresh());
    }

    public function destroy(int $id)
    {
        $c = Campaign::findOrFail($id);
        abort_unless($c->isDraft(), 409, 'Only draft campaigns can be deleted.');
        $c->delete();

        return response()->noContent();
    }

    /** Replace the A/B variants of a draft campaign. Send [] to clear. */
    public function setVariants(Request $r, int $id, \LaravelMail\Services\AbTestService $ab)
    {
        $c = Campaign::findOrFail($id);
        $d = $r->validate(['variants' => 'present|array|max:10', 'variants.*.label' => 'required|string|max:50', 'variants.*.subject' => 'nullable|string|max:255',
            'variants.*.content' => 'nullable|string|max:2000000', 'variants.*.weight' => 'integer|min:1|max:100']);
        try {
            $ab->define($c, $d['variants']);
        } catch (InvalidCampaignStateException $e) {
            abort(409, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $c->variants()->get(['id', 'label', 'subject', 'weight']);
    }

    public function results(Request $r, int $id, \LaravelMail\Services\AbTestService $ab)
    {
        $c = Campaign::findOrFail($id);
        $metric = $r->input('metric', 'open_rate');
        abort_unless(in_array($metric, ['open_rate', 'click_rate'], true), 422, 'metric must be open_rate or click_rate');

        return ['variants' => $ab->results($c), 'leader' => $ab->leader($c, $metric), 'metric' => $metric];
    }

    /** Who would receive it. Works while sending is disabled; sends nothing. */
    public function audience(int $id, CampaignDispatcher $d)
    {
        $c = Campaign::findOrFail($id);
        $rows = $d->preview($c);

        return ['count' => $rows->count(), 'sample' => $rows->take(10)->pluck('email')];
    }

    /** The only endpoint that can send. Needs the campaigns:send ability and features.sending. */
    public function dispatch(int $id, CampaignDispatcher $d)
    {
        $c = Campaign::findOrFail($id);
        try {
            return response()->json(['queued' => $d->dispatch($c)], 202);
        } catch (SendingDisabledException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (InvalidCampaignStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function validated(Request $r, bool $create = true): array
    {
        $req = $create ? 'required' : 'sometimes';

        return $r->validate([
            'name' => "$req|string|max:255", 'subject' => 'nullable|string|max:255', 'content' => 'nullable|string|max:2000000',
            'template_id' => 'nullable|integer|exists:'.(new \LaravelMail\Models\Template)->getTable().',id',
            'from_name' => 'nullable|string|max:255', 'from_email' => 'nullable|email|max:255', 'send_to_all' => 'boolean',
        ]);
    }

    private function sync(Campaign $c, Request $r): void
    {
        $d = $r->validate(['tags' => 'array|max:50', 'tags.*' => 'string|max:100', 'segment_ids' => 'array', 'segment_ids.*' => 'integer', 'excluded_segment_ids' => 'array', 'excluded_segment_ids.*' => 'integer']);
        if (array_key_exists('tags', $d)) {
            $c->tags()->sync(Tag::whereIn('name', $d['tags'])->pluck((new Tag)->getTable().'.id')->all());
        }
        if (array_key_exists('segment_ids', $d)) {
            $c->segments()->detach();
            $c->segments()->attach(\LaravelMail\Models\Segment::whereIn('id', $d['segment_ids'])->pluck('id')->mapWithKeys(fn ($i) => [$i => ['excluded' => false]])->all());
        }
        if (array_key_exists('excluded_segment_ids', $d)) {
            $c->excludedSegments()->detach();
            $c->excludedSegments()->attach(\LaravelMail\Models\Segment::whereIn('id', $d['excluded_segment_ids'])->pluck('id')->mapWithKeys(fn ($i) => [$i => ['excluded' => true]])->all());
        }
    }

    private function out(Campaign $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'subject' => $c->subject, 'template_id' => $c->template_id,
            'send_to_all' => $c->send_to_all, 'tags' => $c->tags->pluck('name')->all(), 'sent_at' => $c->sent_at, 'created_at' => $c->created_at];
    }
}
