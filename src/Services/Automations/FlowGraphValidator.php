<?php

declare(strict_types=1);

namespace LaravelMail\Services\Automations;

/** Checks a submitted automation flow graph (steps with node_key / next_key / alt_key). Ported from the app. */
class FlowGraphValidator
{
    public const TYPE_WAIT = 'wait';
    public const TYPE_SEND_EMAIL = 'send_email';
    public const TYPE_SEND_SMS = 'send_sms';
    public const TYPE_CONDITION = 'condition';
    public const TYPE_WAIT_UNTIL = 'wait_until';
    public const TYPE_GOAL = 'goal';
    public const TYPE_ADD_TAG = 'add_tag';
    public const TYPE_REMOVE_TAG = 'remove_tag';
    public const TYPE_UPDATE_FIELD = 'update_field';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_EXIT = 'exit';

    public const BRANCHING_TYPES = [self::TYPE_CONDITION, self::TYPE_WAIT_UNTIL];

    public const CHECKS = [
        'opened_email' => 'Opened an email from this automation',
        'clicked_email' => 'Clicked an email from this automation',
        'clicked_link' => 'Clicked a link containing (from this automation)',
        'has_tag' => 'Has tag',
        'in_segment' => 'Is in segment',
        'field' => 'Field value',
        'lead_score' => 'Lead score at least',
        'store_event' => 'Store event since entering',
        'page_visited' => 'Visited a page containing (since entering)',
    ];

    /** @return array<int, string> */
    public function errors(array $steps): array
    {
        $errors = [];
        $keys = [];
        foreach ($steps as $i => $step) {
            $key = $step['node_key'] ?? null;
            if (! filled($key)) {
                $errors[] = 'Step '.($i + 1).' needs a node key.';
                continue;
            }
            if (isset($keys[$key])) {
                $errors[] = "Node key \"{$key}\" is used twice.";
            }
            $keys[$key] = $step['type'] ?? null;
        }

        foreach ($steps as $i => $step) {
            $name = $step['name'] ?? ('step '.($i + 1));
            $type = $step['type'] ?? '';
            $config = (array) ($step['config'] ?? []);
            foreach (['next_key', 'alt_key'] as $edge) {
                $target = $step[$edge] ?? null;
                if (filled($target) && ! isset($keys[$target])) {
                    $errors[] = "\"{$name}\" points to a step that does not exist ({$target}).";
                } elseif (filled($target) && $target === ($step['node_key'] ?? null)) {
                    $errors[] = "\"{$name}\" points to itself.";
                } elseif (filled($target) && ($keys[$target] ?? null) === self::TYPE_GOAL) {
                    $errors[] = "\"{$name}\" points to a goal. Goals are checked all the time and are not connected.";
                }
            }
            if (in_array($type, [self::TYPE_GOAL, self::TYPE_EXIT], true)
                && (filled($step['next_key'] ?? null) || filled($step['alt_key'] ?? null))) {
                $errors[] = "\"{$name}\" is a terminal step and cannot lead to another step.";
            }
            if (filled($step['alt_key'] ?? null) && ! in_array($type, self::BRANCHING_TYPES, true)) {
                $errors[] = "\"{$name}\" can't have a \"no\" path.";
            }
            if (in_array($type, [...self::BRANCHING_TYPES, self::TYPE_GOAL], true) && ! array_key_exists($config['check'] ?? '', self::CHECKS)) {
                $errors[] = "\"{$name}\" needs a condition.";
            }
            if (in_array($type, [self::TYPE_ADD_TAG, self::TYPE_REMOVE_TAG], true) && ! filled($config['tag_id'] ?? null)) {
                $errors[] = "\"{$name}\" needs a tag.";
            }
            if ($type === self::TYPE_WEBHOOK && ! str_starts_with((string) ($config['url'] ?? ''), 'https://')) {
                $errors[] = "\"{$name}\" needs an https:// URL.";
            }
            if ($type === self::TYPE_UPDATE_FIELD && ! preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) ($config['field'] ?? ''))) {
                $errors[] = "\"{$name}\" needs a field name (letters, numbers, underscores).";
            }
        }

        // A cycle would repeat actions (tags, field updates, webhooks) forever. Check every component.
        $visiting = [];
        $visited = [];
        $byKey = [];
        foreach ($steps as $step) {
            if (filled($step['node_key'] ?? null)) {
                $byKey[$step['node_key']] = $step;
            }
        }
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, $byKey): bool {
            if (isset($visiting[$key])) {
                return true;
            }
            if (isset($visited[$key]) || ! isset($byKey[$key])) {
                return false;
            }
            $visiting[$key] = true;
            foreach (['next_key', 'alt_key'] as $edge) {
                $target = $byKey[$key][$edge] ?? null;
                if (filled($target) && $visit($target)) {
                    return true;
                }
            }
            unset($visiting[$key]);
            $visited[$key] = true;

            return false;
        };
        foreach (array_keys($byKey) as $key) {
            if ($visit((string) $key)) {
                $errors[] = 'The flow contains a loop. Remove a connection so contacts cannot repeat steps forever.';
                break;
            }
        }

        $starts = array_values(array_filter($steps, fn ($s) => ($s['type'] ?? '') !== self::TYPE_GOAL));
        if ($steps !== [] && $starts === []) {
            $errors[] = 'Add at least one step besides goals.';
        }

        return array_values(array_unique($errors));
    }
}
