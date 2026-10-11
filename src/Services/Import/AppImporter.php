<?php

declare(strict_types=1);

namespace LaravelMail\Services\Import;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LaravelMail\Models\{Conversation, ConversationMessage, Deal, ImportMap, Subscriber, Tag};
use LaravelMail\Support\PhoneNumber;

/**
 * Mapping layer for adopting the package alongside the hosted app's schema. It READS the app's tables over a
 * configured connection (never writes to it) and copies them into the package's clean tables. Repeatable: an id map
 * means a second run only adds what is new. One workspace per run, so tenants never mix. Dry-run unless told otherwise.
 *
 * Source table/column names are the app's; override table names with laravelmail.import.tables if yours differ.
 */
class AppImporter
{
    public const ENTITIES = ['tags', 'subscribers', 'deals', 'conversations'];
    private const SRC = 'app';

    private ConnectionInterface $db;
    /** @var array<string,array<string,int>> */
    private array $stats = [];

    public function __construct(private string $workspaceId, ConnectionInterface|string|null $connection = null, private bool $commit = false)
    {
        $this->db = $connection instanceof ConnectionInterface ? $connection : DB::connection($connection ?: config('laravelmail.import.connection'));
    }

    /** @return array<string,array{seen:int,created:int,existing:int,skipped:int}> */
    public function run(array $entities = self::ENTITIES): array
    {
        $unknown = array_diff($entities, self::ENTITIES);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown entities: '.implode(', ', $unknown));
        }
        foreach (self::ENTITIES as $e) { // fixed order: later entities depend on earlier ones
            if (in_array($e, $entities, true)) {
                $this->$e();
            }
        }

        return $this->stats;
    }

    private function table(string $name): string
    {
        return config("laravelmail.import.tables.$name", $name);
    }

    private function src(string $name)
    {
        return $this->db->table($this->table($name))->where('workspace_id', (int) $this->workspaceId);
    }

    private function bump(string $entity, string $k): void
    {
        $this->stats[$entity] ??= ['seen' => 0, 'created' => 0, 'existing' => 0, 'skipped' => 0];
        $this->stats[$entity][$k]++;
    }

    private function mapped(string $entity, int|string $sourceId): ?int
    {
        return ImportMap::where(['source' => self::SRC, 'entity' => $entity, 'source_id' => (string) $sourceId])->value('target_id');
    }

    private function remember(string $entity, int|string $sourceId, int $targetId): void
    {
        ImportMap::firstOrCreate(['source' => self::SRC, 'entity' => $entity, 'source_id' => (string) $sourceId], ['target_id' => $targetId]);
    }

    private function tags(): void
    {
        $this->src('tags')->orderBy('id')->each(function ($r) {
            $this->bump('tags', 'seen');
            if ($this->mapped('tags', $r->id)) {
                $this->bump('tags', 'existing');

                return;
            }
            $name = trim((string) $r->name);
            if ($name === '') {
                $this->bump('tags', 'skipped');

                return;
            }
            if (! $this->commit) {
                $this->bump('tags', 'created');

                return;
            }
            $t = Tag::withoutGlobalScopes()->firstOrCreate(['workspace_id' => $this->workspaceId, 'name' => $name]);
            $this->remember('tags', $r->id, $t->id);
            $this->bump('tags', $t->wasRecentlyCreated ? 'created' : 'existing');
        });
    }

    private function subscribers(): void
    {
        $hasPhone = $this->db->getSchemaBuilder()->hasColumn($this->table('subscribers'), 'phone');
        $hasConsent = $this->db->getSchemaBuilder()->hasColumn($this->table('subscribers'), 'sms_consent_at');
        $this->src('subscribers')->orderBy('id')->each(function ($r) use ($hasPhone, $hasConsent) {
            $this->bump('subscribers', 'seen');
            if ($this->mapped('subscribers', $r->id)) {
                $this->bump('subscribers', 'existing');
                $this->linkTags($r->id);

                return;
            }
            $email = strtolower(trim((string) $r->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->bump('subscribers', 'skipped');

                return;
            }
            if (! $this->commit) {
                $this->bump('subscribers', 'created');

                return;
            }
            $s = Subscriber::withoutGlobalScopes()->firstOrNew(['workspace_id' => $this->workspaceId, 'email' => $email]);
            $isNew = ! $s->exists;
            if ($isNew) {
                $meta = is_string($r->meta ?? null) ? json_decode($r->meta, true) : ($r->meta ?? null);
                $s->fill(['first_name' => $r->first_name, 'last_name' => $r->last_name, 'meta' => is_array($meta) ? $meta : null,
                    'status' => $r->unsubscribed_at ? 'unsubscribed' : 'subscribed', 'unsubscribed_at' => $r->unsubscribed_at, 'created_at' => $r->created_at]);
                if ($hasPhone && PhoneNumber::isValid($r->phone ?? null)) {
                    $s->phone = PhoneNumber::normalize($r->phone);
                    // Consent is only carried over when the app recorded when it was given. Never invented.
                    if ($hasConsent && ! empty($r->sms_consent_at)) {
                        $s->sms_consent_at = $r->sms_consent_at;
                    }
                }
                $s->save();
            }
            $this->remember('subscribers', $r->id, $s->id);
            $this->bump('subscribers', $isNew ? 'created' : 'existing');
            $this->linkTags($r->id);
        });
    }

    private function linkTags(int $sourceSubscriberId): void
    {
        if (! $this->commit || ! ($sid = $this->mapped('subscribers', $sourceSubscriberId))) {
            return;
        }
        $ids = [];
        foreach ($this->db->table($this->table('tag_subscriber'))->where('subscriber_id', $sourceSubscriberId)->pluck('tag_id') as $srcTag) {
            if ($t = $this->mapped('tags', $srcTag)) {
                $ids[] = $t;
            }
        }
        $ids && Subscriber::withoutGlobalScopes()->find($sid)?->tags()->syncWithoutDetaching($ids); // no scoring events: this is history, not new activity
    }

    private function deals(): void
    {
        $this->src('deals')->orderBy('id')->each(function ($r) {
            $this->bump('deals', 'seen');
            if ($this->mapped('deals', $r->id)) {
                $this->bump('deals', 'existing');

                return;
            }
            if (! array_key_exists((string) $r->stage, Deal::STAGES) || trim((string) $r->title) === '') {
                $this->bump('deals', 'skipped');

                return;
            }
            $sub = $r->subscriber_id ? $this->mapped('subscribers', $r->subscriber_id) : null;
            if ($r->subscriber_id && ! $sub) {
                $this->bump('deals', 'skipped'); // its subscriber was not imported (run subscribers first)

                return;
            }
            if (! $this->commit) {
                $this->bump('deals', 'created');

                return;
            }
            $d = Deal::withoutGlobalScopes()->create(['workspace_id' => $this->workspaceId, 'subscriber_id' => $sub, 'title' => $r->title, 'value' => $r->value, 'currency' => $r->currency,
                'stage' => $r->stage, 'expected_close_on' => $r->expected_close_on, 'notes' => $r->notes, 'closed_at' => $r->closed_at, 'created_at' => $r->created_at]);
            $this->remember('deals', $r->id, $d->id);
            $this->bump('deals', 'created');
        });
    }

    private function conversations(): void
    {
        $this->src('conversations')->orderBy('id')->each(function ($r) {
            $this->bump('conversations', 'seen');
            $existing = $this->mapped('conversations', $r->id);
            $sub = $this->mapped('subscribers', $r->subscriber_id);
            if (! $existing && ! $sub) {
                $this->bump('conversations', 'skipped');

                return;
            }
            if (! $this->commit) {
                $this->bump('conversations', $existing ? 'existing' : 'created');

                return;
            }
            $c = $existing ? Conversation::withoutGlobalScopes()->find($existing) : Conversation::withoutGlobalScopes()->create([
                'workspace_id' => $this->workspaceId, 'subscriber_id' => $sub, 'subject' => $r->subject, 'status' => $r->status === 'closed' ? 'closed' : 'open',
                'last_reply_at' => $r->last_reply_at, 'created_by' => $r->created_by === null ? null : (string) $r->created_by, 'created_at' => $r->created_at]);
            if (! $existing) {
                $this->remember('conversations', $r->id, $c->id);
            }
            $this->bump('conversations', $existing ? 'existing' : 'created');
            $this->conversationMessages($r->id, $c);
        });
    }

    private function conversationMessages(int $sourceConversationId, Conversation $c): void
    {
        $this->db->table($this->table('conversation_messages'))->where('conversation_id', $sourceConversationId)->orderBy('id')->each(function ($m) use ($c) {
            $this->bump('conversation_messages', 'seen');
            if ($this->mapped('conversation_messages', $m->id)) {
                $this->bump('conversation_messages', 'existing');

                return;
            }
            $row = ConversationMessage::withoutGlobalScopes()->create(['workspace_id' => $this->workspaceId, 'conversation_id' => $c->id,
                'sender_type' => $m->sender_type === 'subscriber' ? 'subscriber' : 'user', 'sender_id' => $m->sender_id === null ? null : (string) $m->sender_id,
                'body' => $m->body, 'provider_message_id' => $m->message_id, 'read_at' => $m->read_at, 'created_at' => $m->created_at,
                'sent_at' => $m->sender_type === 'user' ? $m->created_at : null]); // history: already emailed by the app
            $this->remember('conversation_messages', $m->id, $row->id);
            $this->bump('conversation_messages', 'created');
        });
    }
}
