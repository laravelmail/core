<?php

declare(strict_types=1);

namespace LaravelMail\Services\Subscribers;

use Illuminate\Database\Eloquent\Builder;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Tag;

/** Bulk tag / untag / unsubscribe / delete over an explicit selection. An empty selection is refused, never "everyone". */
class BulkActions
{
    public const ACTIONS = ['tag', 'untag', 'unsubscribe', 'delete'];

    public function __construct(private TagService $tags)
    {
    }

    /** @param array{ids?:int[],tag?:string,status?:string,email_contains?:string} $selection */
    public function selection(array $selection): Builder
    {
        $q = Subscriber::query();
        $any = false;
        if (! empty($selection['ids'])) {
            $q->whereIn('id', array_map('intval', $selection['ids']));
            $any = true;
        }
        if (! empty($selection['tag'])) {
            $q->whereHas('tags', fn ($t) => $t->where((new Tag)->getTable().'.name', $selection['tag']));
            $any = true;
        }
        if (! empty($selection['status'])) {
            $q->where('status', $selection['status']);
            $any = true;
        }
        if (! empty($selection['email_contains'])) {
            $q->where('email', 'like', '%'.addcslashes(strtolower($selection['email_contains']), '%_\\').'%');
            $any = true;
        }
        if (! $any) {
            throw new \InvalidArgumentException('Give a selection: ids, tag, status or email_contains.');
        }

        return $q;
    }

    /** @return int subscribers affected */
    public function run(string $action, array $selection, ?string $tagName = null, bool $dryRun = false): int
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown action. Use: '.implode(', ', self::ACTIONS));
        }
        if (in_array($action, ['tag', 'untag'], true) && blank($tagName)) {
            throw new \InvalidArgumentException('tag name required');
        }
        $q = $this->selection($selection);
        if ($dryRun) {
            return $q->count();
        }

        return match ($action) {
            'tag' => $this->tags->tagQuery($q, (string) $tagName),
            'untag' => $this->untag($q, (string) $tagName),
            'unsubscribe' => $this->each($q, fn (Subscriber $s) => $s->isSubscribed() ? $s->unsubscribe() : null),
            'delete' => $this->each($q, fn (Subscriber $s) => $s->delete()),
        };
    }

    private function untag(Builder $q, string $name): int
    {
        $tag = Tag::where('name', trim($name))->first();
        if (! $tag) {
            return 0;
        }
        $n = 0;
        (clone $q)->select((new Subscriber)->getTable().'.id')->chunkById(1000, function ($chunk) use ($tag, &$n) {
            $n += $tag->subscribers()->detach($chunk->pluck('id')->all());
        }, (new Subscriber)->getTable().'.id', 'id');

        return $n;
    }

    private function each(Builder $q, \Closure $f): int
    {
        $n = 0;
        $q->chunkById(500, function ($chunk) use ($f, &$n) {
            foreach ($chunk as $s) {
                $f($s);
                $n++;
            }
        });

        return $n;
    }
}
