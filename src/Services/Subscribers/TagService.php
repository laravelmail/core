<?php

declare(strict_types=1);

namespace LaravelMail\Services\Subscribers;

use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Tag;

class TagService
{
    /** @param iterable<string> $names */
    public function attach(Subscriber $subscriber, iterable $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $ids[] = Tag::firstOrCreate(['name' => $name])->id;
            }
        }
        $subscriber->tags()->syncWithoutDetaching($ids);
    }

    public function detach(Subscriber $subscriber, iterable $names): void
    {
        $ids = Tag::whereIn('name', collect($names)->map(fn ($n) => trim((string) $n))->all())->pluck('id');
        $subscriber->tags()->detach($ids);
    }

    /** Bulk-tag subscribers matching a query without loading them. Returns rows attached. */
    public function tagQuery(\Illuminate\Database\Eloquent\Builder $subscribers, string $tagName): int
    {
        $tag = Tag::firstOrCreate(['name' => trim($tagName)]);
        $n = 0;
        $subscribers->select((new Subscriber)->getTable().'.id')->chunkById(1000, function ($chunk) use ($tag, &$n) {
            $tag->subscribers()->syncWithoutDetaching($chunk->pluck('id')->all());
            $n += $chunk->count();
        }, (new Subscriber)->getTable().'.id', 'id');

        return $n;
    }
}
