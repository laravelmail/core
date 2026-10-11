<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Tag;
use LaravelMail\Services\Subscribers\TagService;

class SubscribersController
{
    public function index(Request $r)
    {
        $q = Subscriber::query()->with('tags:id,name')->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->string('status'));
        }
        if ($r->filled('email')) {
            $q->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower((string) $r->input('email'))).'%']);
        }
        if ($r->filled('tag')) {
            $q->whereHas('tags', fn ($t) => $t->where((new Tag)->getTable().'.name', $r->string('tag')));
        }

        return $q->paginate(min(max((int) $r->input('per_page', 50), 1), 200))->through(fn ($s) => $this->out($s));
    }

    public function store(Request $r, TagService $tags)
    {
        $d = $r->validate([
            'email' => 'required|email:rfc|max:255', 'first_name' => 'nullable|string|max:255', 'last_name' => 'nullable|string|max:255',
            'meta' => 'nullable|array', 'phone' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v === null || \LaravelMail\Support\PhoneNumber::isValid($v) ?: $fail('The phone must be in international format, e.g. +447700900123.')], 'tags' => 'array|max:50', 'tags.*' => 'string|max:100',
        ]);
        $email = strtolower($d['email']);
        if (\LaravelMail\Models\Suppression::isSuppressed($email)) {
            return response()->json(['message' => 'This address is suppressed.'], 422);
        }
        $s = Subscriber::firstOrNew(['email' => $email]);
        $new = ! $s->exists;
        // An API write never re-subscribes someone who unsubscribed.
        $s->fill(array_filter(['first_name' => $d['first_name'] ?? null, 'last_name' => $d['last_name'] ?? null, 'meta' => $d['meta'] ?? null, 'phone' => \LaravelMail\Support\PhoneNumber::normalize($d['phone'] ?? null)], fn ($v) => $v !== null))->save();
        $tags->attach($s, $d['tags'] ?? []);

        return response()->json($this->out($s->load('tags:id,name')), $new ? 201 : 200);
    }

    public function show(int $id)
    {
        return $this->out(Subscriber::with('tags:id,name')->findOrFail($id));
    }

    public function update(Request $r, int $id)
    {
        $s = Subscriber::findOrFail($id);
        $d = $r->validate(['first_name' => 'nullable|string|max:255', 'last_name' => 'nullable|string|max:255', 'meta' => 'nullable|array', 'phone' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v === null || \LaravelMail\Support\PhoneNumber::isValid($v) ?: $fail('The phone must be in international format, e.g. +447700900123.')]]);
        $s->fill($d)->save();

        return $this->out($s->load('tags:id,name'));
    }

    public function destroy(int $id)
    {
        Subscriber::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function attachTags(Request $r, int $id, TagService $tags)
    {
        $d = $r->validate(['tags' => 'required|array|max:50', 'tags.*' => 'string|max:100']);
        $tags->attach($s = Subscriber::findOrFail($id), $d['tags']);

        return $this->out($s->load('tags:id,name'));
    }

    public function detachTags(Request $r, int $id, TagService $tags)
    {
        $d = $r->validate(['tags' => 'required|array|max:50', 'tags.*' => 'string|max:100']);
        $tags->detach($s = Subscriber::findOrFail($id), $d['tags']);

        return $this->out($s->load('tags:id,name'));
    }

    private function out(Subscriber $s): array
    {
        return ['id' => $s->id, 'email' => $s->email, 'first_name' => $s->first_name, 'last_name' => $s->last_name,
            'status' => $s->status, 'phone' => $s->phone, 'score' => $s->score, 'sms_consent' => $s->sms_consent_at !== null && $s->sms_opted_out_at === null, 'meta' => $s->meta, 'tags' => $s->tags->pluck('name')->all(),
            'created_at' => $s->created_at, 'unsubscribed_at' => $s->unsubscribed_at];
    }
}
