<?php

declare(strict_types=1);

namespace LaravelMail\Services\Subscribers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Tag;

/** Topic subscriptions (tags marked preference_topic) and self-service unsubscribe/resubscribe. */
class PreferenceCenter
{
    public function url(Subscriber $s): string
    {
        return URL::signedRoute('laravelmail.preferences.show', ['subscriber' => $s->id]);
    }

    public function topics(): Collection
    {
        return Tag::where('preference_topic', true)->orderBy('name')->get();
    }

    /** @return int[] */
    public function selectedTopicIds(Subscriber $s): array
    {
        $topics = $this->topics();

        return $topics->isEmpty() ? [] : $s->tags()->whereIn((new Tag)->getTable().'.id', $topics->modelKeys())
            ->pluck((new Tag)->getTable().'.id')->map(fn ($i) => (int) $i)->all();
    }

    /** Only tags that are preference topics can be changed here: callers cannot touch internal tags. */
    public function updateTopics(Subscriber $s, array $requested): array
    {
        $topicIds = $this->topics()->modelKeys();
        $wanted = array_values(array_intersect($topicIds, array_map('intval', $requested)));
        $current = $this->selectedTopicIds($s);
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        DB::transaction(function () use ($s, $added, $removed) {
            $added && $s->tags()->attach($added);
            $removed && $s->tags()->detach($removed);
        });

        return ['added' => $added, 'removed' => $removed];
    }

    /** Only self-unsubscribes can be undone by the subscriber. Bounce/complaint suppressions cannot. */
    public function canResubscribe(Subscriber $s): bool
    {
        return $s->status === 'unsubscribed' && ($s->meta['unsubscribe_reason'] ?? 'self') === 'self'
            && ! \LaravelMail\Models\Suppression::isSuppressed($s->email);
    }

    public function resubscribe(Subscriber $s): bool
    {
        if (! $this->canResubscribe($s)) {
            return false;
        }
        $s->forceFill(['status' => 'subscribed', 'unsubscribed_at' => null])->save();

        return true;
    }
}
