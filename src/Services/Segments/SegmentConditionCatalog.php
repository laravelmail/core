<?php

declare(strict_types=1);

namespace LaravelMail\Services\Segments;

/**
 * Which conditions a segment rule tree may contain. The rule-tree JSON shape and condition names
 * match the hosted app, so rules are portable between the two. Slice 2 supports the conditions that
 * only need core tables; app-only types (store_event, total_spent, lead_score, fit_grade, page_visited,
 * link_clicked, engagement) arrive with their modules.
 */
final class SegmentConditionCatalog
{
    public const MAX_DEPTH = 6;
    public const MAX_CONDITIONS = 200;
    public const MATCH_MODES = ['all', 'any'];
    public const SUBSCRIBER_FIELDS = ['email', 'first_name', 'last_name'];

    /** @return array<string, array<string, string>> type => operator => label */
    public static function types(): array
    {
        return [
            'field' => ['equals' => 'is', 'not_equals' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain',
                'starts_with' => 'starts with', 'ends_with' => 'ends with', 'is_empty' => 'is empty', 'is_not_empty' => 'is not empty'],
            'meta' => ['equals' => 'is', 'not_equals' => 'is not', 'exists' => 'is set', 'not_exists' => 'is not set'],
            'status' => ['subscribed' => 'is subscribed', 'unsubscribed' => 'is unsubscribed'],
            'created_at' => ['before' => 'before date', 'after' => 'after date', 'within_days' => 'within the last N days', 'older_than_days' => 'more than N days ago'],
            'tag' => ['has' => 'has tag', 'has_not' => 'does not have tag'],
            'segment' => ['in' => 'is in segment', 'not_in' => 'is not in segment'],
            'campaign_sent' => ['is' => 'was sent', 'is_not' => 'was not sent'],
            'campaign_opened' => ['is' => 'opened', 'is_not' => 'did not open'],
            'campaign_clicked' => ['is' => 'clicked', 'is_not' => 'did not click'],
        ];
    }

    public static function operatorsFor(string $type): array
    {
        return array_keys(self::types()[$type] ?? []);
    }

    public static function isGroup(mixed $node): bool
    {
        return is_array($node) && array_key_exists('rules', $node);
    }
}
