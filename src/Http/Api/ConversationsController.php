<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Conversation;
use LaravelMail\Models\ConversationMessage;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\Conversations\ConversationService;

class ConversationsController
{
    public function index(Request $r)
    {
        $q = Conversation::with('subscriber:id,email')->orderByDesc('last_reply_at')->orderByDesc('id');
        $r->filled('status') && $q->where('status', $r->string('status'));

        return $q->paginate(min(max((int) $r->input('per_page', 50), 1), 200));
    }

    public function show(int $id)
    {
        return Conversation::with(['subscriber:id,email', 'messages'])->findOrFail($id);
    }

    public function store(Request $r, ConversationService $svc)
    {
        $d = $r->validate(['subscriber_id' => 'required|integer', 'subject' => 'required|string|max:255', 'body' => 'required|string|max:20000']);
        $c = $svc->open(Subscriber::findOrFail($d['subscriber_id']), $d['subject'], $d['body']);

        return response()->json($c->load('messages'), 201);
    }

    /** Records an agent reply. Pass send=true to also email it (needs conversations:send, features.sending and a subscribed recipient). */
    public function reply(Request $r, int $id, ConversationService $svc)
    {
        $d = $r->validate(['body' => 'required|string|max:20000', 'send' => 'boolean']);
        $m = $svc->reply(Conversation::findOrFail($id), $d['body']);
        $sent = false;
        if ($d['send'] ?? false) {
            abort_unless($r->attributes->get('laravelmail_token')?->can('conversations:send'), 403, 'This token cannot send replies.');
            $sent = $svc->deliver($m->load('conversation.subscriber'));
        }

        return response()->json(['message' => $m->fresh(), 'emailed' => $sent], 201);
    }

    public function close(int $id, ConversationService $svc)
    {
        $svc->close($c = Conversation::findOrFail($id));

        return $c->fresh();
    }

    public function read(int $id)
    {
        ConversationMessage::where('conversation_id', Conversation::findOrFail($id)->id)->where('sender_type', 'subscriber')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }
}
