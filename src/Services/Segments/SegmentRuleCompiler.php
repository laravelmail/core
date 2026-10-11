<?php

declare(strict_types=1);

namespace LaravelMail\Services\Segments;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use LaravelMail\Models\Message;
use LaravelMail\Models\Segment;
use LaravelMail\Models\Subscriber;

/**
 * Compiles a rule tree into a subscriber query:
 *   {"match": "all"|"any", "not": false, "rules": [ <condition> | <group>, ... ]}
 *   condition: {"type": "tag", "operator": "has", "value": 3}
 * Negatives use NOT EXISTS / NOT IN / explicit NULL handling so NULL columns are never dropped.
 */
class SegmentRuleCompiler
{
    public function query(array $tree, array $visited = []): Builder
    {
        $query = Subscriber::query();
        $this->apply($query, $tree, $visited);

        return $query;
    }

    public function apply(Builder $query, array $tree, array $visited = []): Builder
    {
        $this->assertSize($tree);
        $query->where(fn (Builder $q) => $this->applyGroup($q, $tree, $visited, 1));

        return $query;
    }

    public function idSubquery(array $tree, array $visited = []): Builder
    {
        $t = (new Subscriber)->getTable();

        return Subscriber::query()->select("{$t}.id")
            ->where(fn (Builder $q) => $this->applyGroup($q, $tree, $visited, 1));
    }

    private function assertSize(array $tree): void
    {
        $count = 0;
        $walk = function (array $n) use (&$walk, &$count) {
            foreach ($n['rules'] ?? [] as $r) {
                if (! is_array($r)) {
                    continue;
                }
                SegmentConditionCatalog::isGroup($r) ? $walk($r) : $count++;
            }
        };
        $walk($tree);
        if ($count > SegmentConditionCatalog::MAX_CONDITIONS) {
            throw new SegmentRuleException('Segment has too many conditions.');
        }
    }

    private function applyGroup(Builder $query, array $group, array $visited, int $depth): void
    {
        if ($depth > SegmentConditionCatalog::MAX_DEPTH) {
            throw new SegmentRuleException('Segment rules are nested too deeply.');
        }
        $t = (new Subscriber)->getTable();

        if (! empty($group['not'])) {
            $inner = $group;
            unset($inner['not']);
            $query->whereNotIn("{$t}.id", $this->idSubquery($inner, $visited));

            return;
        }

        $rules = array_values(array_filter($group['rules'] ?? [], 'is_array'));
        if ($rules === []) {
            return; // empty group matches everyone
        }
        $boolean = ($group['match'] ?? 'all') === 'any' ? 'or' : 'and';

        foreach ($rules as $node) {
            $query->where(function (Builder $sub) use ($node, $visited, $depth) {
                SegmentConditionCatalog::isGroup($node)
                    ? $this->applyGroup($sub, $node, $visited, $depth + 1)
                    : $this->applyCondition($sub, $node, $visited);
            }, null, null, $boolean);
        }
    }

    private function applyCondition(Builder $query, array $rule, array $visited): void
    {
        $type = (string) ($rule['type'] ?? '');
        $op = (string) ($rule['operator'] ?? '');
        $value = $rule['value'] ?? null;

        if (! in_array($op, SegmentConditionCatalog::operatorsFor($type), true)) {
            throw new SegmentRuleException("Unsupported condition [{$type}:{$op}].");
        }
        $t = (new Subscriber)->getTable();

        match ($type) {
            'field' => $this->field($query, $t, (string) ($rule['field'] ?? ''), $op, $value),
            'meta' => $this->meta($query, $t, (string) ($rule['field'] ?? ''), $op, $value),
            'status' => $op === 'subscribed'
                ? $query->where("{$t}.status", 'subscribed')
                : $query->where("{$t}.status", '<>', 'subscribed'),
            'created_at' => $this->createdAt($query, $t, $op, $value),
            'tag' => $this->tag($query, $t, $op, (int) $value),
            'segment' => $this->segment($query, $t, $op, (int) $value, $visited),
            'lead_score' => $query->where("{$t}.score", $op === 'at_least' ? '>=' : '<=', (int) $value),
            'campaign_sent' => $this->message($query, $t, $op === 'is', (int) $value, 'sent_at'),
            'campaign_opened' => $this->message($query, $t, $op === 'is', (int) $value, 'opened_at'),
            'campaign_clicked' => $this->message($query, $t, $op === 'is', (int) $value, 'clicked_at'),
            default => throw new SegmentRuleException('Unsupported condition.'),
        };
    }

    private function field(Builder $q, string $t, string $field, string $op, mixed $value): void
    {
        if (! in_array($field, SegmentConditionCatalog::SUBSCRIBER_FIELDS, true)) {
            throw new SegmentRuleException("Unsupported subscriber field [{$field}].");
        }
        $col = "{$t}.{$field}";
        $lower = "LOWER({$col})";
        $needle = mb_strtolower((string) $value);
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle); // '!' as LIKE escape char: same meaning on MySQL, Postgres and SQLite

        match ($op) {
            'equals' => $q->whereRaw("{$lower} = ?", [$needle]),
            'not_equals' => $q->where(fn ($x) => $x->whereNull($col)->orWhereRaw("{$lower} <> ?", [$needle])),
            'contains' => $q->whereRaw("{$lower} LIKE ? ESCAPE '!'", ['%'.$like.'%']),
            'not_contains' => $q->where(fn ($x) => $x->whereNull($col)->orWhereRaw("{$lower} NOT LIKE ? ESCAPE '!'", ['%'.$like.'%'])),
            'starts_with' => $q->whereRaw("{$lower} LIKE ? ESCAPE '!'", [$like.'%']),
            'ends_with' => $q->whereRaw("{$lower} LIKE ? ESCAPE '!'", ['%'.$like]),
            'is_empty' => $q->where(fn ($x) => $x->whereNull($col)->orWhere($col, '')),
            'is_not_empty' => $q->whereNotNull($col)->where($col, '<>', ''),
            default => throw new SegmentRuleException('Unsupported condition.'),
        };
    }

    private function meta(Builder $q, string $t, string $key, string $op, mixed $value): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $key)) {
            throw new SegmentRuleException('Custom field keys may only contain letters, numbers and underscores.');
        }
        $path = "{$t}.meta->{$key}";

        match ($op) {
            'equals' => $q->where($path, $value),
            'not_equals' => $q->where(fn ($x) => $x->whereNull($path)->orWhere($path, '<>', $value)),
            'exists' => $q->whereNotNull($path),
            'not_exists' => $q->whereNull($path),
            default => throw new SegmentRuleException('Unsupported condition.'),
        };
    }

    private function createdAt(Builder $q, string $t, string $op, mixed $value): void
    {
        match ($op) {
            'before' => $q->where("{$t}.created_at", '<', Carbon::parse((string) $value)->startOfDay()),
            'after' => $q->where("{$t}.created_at", '>', Carbon::parse((string) $value)->endOfDay()),
            'within_days' => $q->where("{$t}.created_at", '>=', now()->subDays((int) $value)),
            'older_than_days' => $q->where("{$t}.created_at", '<', now()->subDays((int) $value)),
            default => throw new SegmentRuleException('Unsupported condition.'),
        };
    }

    private function tag(Builder $q, string $t, string $op, int $tagId): void
    {
        $pivot = config('laravelmail.table_prefix').'subscriber_tag';
        $method = $op === 'has' ? 'whereExists' : 'whereNotExists';
        $q->{$method}(fn ($s) => $s->selectRaw('1')->from($pivot)
            ->whereColumn("{$pivot}.subscriber_id", "{$t}.id")->where("{$pivot}.tag_id", $tagId));
    }

    private function segment(Builder $q, string $t, string $op, int $segmentId, array $visited): void
    {
        if (in_array($segmentId, $visited, true)) {
            throw new SegmentRuleException('Segments reference each other in a loop.');
        }
        $segment = Segment::find($segmentId);
        if (! $segment) {
            throw new SegmentRuleException("Segment [{$segmentId}] not found.");
        }
        $sub = $this->idSubquery((array) ($segment->rules ?? []), [...$visited, $segmentId]);
        $op === 'in' ? $q->whereIn("{$t}.id", $sub) : $q->whereNotIn("{$t}.id", $sub);
    }

    private function message(Builder $q, string $t, bool $positive, int $campaignId, string $column): void
    {
        $m = (new Message)->getTable();
        $method = $positive ? 'whereExists' : 'whereNotExists';
        $q->{$method}(fn ($s) => $s->selectRaw('1')->from($m)
            ->whereColumn("{$m}.subscriber_id", "{$t}.id")
            ->where("{$m}.campaign_id", $campaignId)->whereNotNull("{$m}.{$column}"));
    }
}
