<?php

declare(strict_types=1);

namespace LaravelMail\Services\Subscribers;

use InvalidArgumentException;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;
use LaravelMail\Models\Tag;

/**
 * Imports subscribers from CSV (header row required: email, optional first_name, last_name, tags).
 * `tags` is a semicolon-separated list. Existing subscribers are updated, never re-subscribed if they unsubscribed.
 * Sends nothing.
 */
class SubscriberImporter
{
    /** @return array{created:int,updated:int,skipped:int,invalid:int,errors:list<string>} */
    public function importFile(string $path, array $extraTags = []): array
    {
        $h = @fopen($path, 'rb');
        if (! $h) {
            throw new InvalidArgumentException("Cannot read {$path}.");
        }
        try {
            return $this->import($h, $extraTags);
        } finally {
            fclose($h);
        }
    }

    /** @param resource $handle */
    public function import($handle, array $extraTags = []): array
    {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header) {
            throw new InvalidArgumentException('Empty CSV.');
        }
        $cols = array_map(fn ($c) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $header);
        if (! in_array('email', $cols, true)) {
            throw new InvalidArgumentException('CSV needs an "email" column.');
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'errors' => []];
        $suppressed = array_flip(Suppression::query()->pluck('email')->all());
        $tags = [];
        $line = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }
            $data = array_combine($cols, array_pad(array_slice($row, 0, count($cols)), count($cols), null));
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['invalid']++;
                if (count($stats['errors']) < 20) {
                    $stats['errors'][] = "Line {$line}: invalid email";
                }
                continue;
            }
            if (isset($suppressed[$email])) {
                $stats['skipped']++;
                continue;
            }

            $attrs = array_filter([
                'first_name' => $this->clean($data['first_name'] ?? null),
                'last_name' => $this->clean($data['last_name'] ?? null),
            ], fn ($v) => $v !== null);

            $sub = Subscriber::where('email', $email)->first();
            if ($sub) {
                $sub->fill($attrs)->save();
                $stats['updated']++;
            } else {
                $sub = Subscriber::create(['email' => $email] + $attrs);
                $stats['created']++;
            }

            $names = array_unique([...$extraTags, ...array_filter(array_map('trim', explode(';', (string) ($data['tags'] ?? ''))))]);
            $ids = [];
            foreach ($names as $name) {
                $ids[] = ($tags[$name] ??= Tag::firstOrCreate(['name' => mb_substr($name, 0, 100)])->id);
            }
            if ($ids) {
                $sub->tags()->syncWithoutDetaching($ids);
            }
        }

        return $stats;
    }

    private function clean(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 255);
    }
}
