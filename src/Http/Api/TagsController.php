<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Tag;

class TagsController
{
    public function index()
    {
        return Tag::withCount('subscribers')->orderBy('name')->get()->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'subscribers' => $t->subscribers_count, 'preference_topic' => $t->preference_topic]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'preference_topic' => 'boolean']);
        $t = Tag::firstOrCreate(['name' => trim($d['name'])], ['preference_topic' => $d['preference_topic'] ?? false]);

        return response()->json(['id' => $t->id, 'name' => $t->name], $t->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(int $id)
    {
        Tag::findOrFail($id)->delete();

        return response()->noContent();
    }
}
