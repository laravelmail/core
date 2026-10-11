<?php

declare(strict_types=1);

namespace LaravelMail\Http;

use Illuminate\Http\Request;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\Subscribers\PreferenceCenter;

/** JSON only: the host app renders its own UI. All routes are signed. */
class PreferencesController
{
    public function show(int $subscriber, PreferenceCenter $pc)
    {
        $s = Subscriber::findOrFail($subscriber);

        return response()->json([
            'email' => $s->email,
            'status' => $s->status,
            'topics' => $pc->topics()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'selected' => $pc->selectedTopicIds($s),
            'can_resubscribe' => $pc->canResubscribe($s),
        ]);
    }

    public function update(Request $request, int $subscriber, PreferenceCenter $pc)
    {
        $s = Subscriber::findOrFail($subscriber);
        $data = $request->validate(['topics' => 'array', 'topics.*' => 'integer']);

        return response()->json($pc->updateTopics($s, $data['topics'] ?? []));
    }

    public function resubscribe(int $subscriber, PreferenceCenter $pc)
    {
        abort_unless($pc->resubscribe(Subscriber::findOrFail($subscriber)), 403);

        return response()->json(['status' => 'subscribed']);
    }
}
