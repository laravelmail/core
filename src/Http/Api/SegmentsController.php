<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Segment;
use LaravelMail\Services\Segments\SegmentRuleCompiler;
use LaravelMail\Services\Segments\SegmentRuleException;

class SegmentsController
{
    public function index()
    {
        return Segment::orderBy('name')->get(['id', 'name', 'rules']);
    }

    public function store(Request $r, SegmentRuleCompiler $c)
    {
        $d = $this->validated($r, $c);

        return response()->json(Segment::create($d), 201);
    }

    public function show(int $id, SegmentRuleCompiler $c)
    {
        $s = Segment::findOrFail($id);

        return ['id' => $s->id, 'name' => $s->name, 'rules' => $s->rules, 'matching' => $c->query((array) $s->rules)->count()];
    }

    public function update(Request $r, int $id, SegmentRuleCompiler $c)
    {
        $s = Segment::findOrFail($id);
        $s->update($this->validated($r, $c));

        return $s;
    }

    public function destroy(int $id)
    {
        Segment::findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Rules are compiled once on write so a bad tree is rejected with 422 instead of failing at send time. */
    private function validated(Request $r, SegmentRuleCompiler $c): array
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'rules' => 'required|array']);
        try {
            $c->query($d['rules'])->limit(1)->get();
        } catch (SegmentRuleException $e) {
            abort(422, $e->getMessage());
        }

        return $d;
    }
}
