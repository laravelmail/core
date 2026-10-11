<?php

declare(strict_types=1);

namespace LaravelMail\Services\Subscribers;

use Illuminate\Database\Eloquent\Builder;
use LaravelMail\Models\Subscriber;

class SubscriberExporter
{
    public const COLUMNS = ['email', 'first_name', 'last_name', 'status', 'tags', 'created_at'];

    /** Stream CSV to a resource (php://output, a file). Cells starting with = + - @ are neutralised against spreadsheet formula injection. */
    public function write($handle, ?Builder $query = null): int
    {
        $query ??= Subscriber::query();
        fputcsv($handle, self::COLUMNS, ',', '"', '');
        $n = 0;
        $query->with('tags:id,name')->orderBy('id')->chunkById(1000, function ($chunk) use ($handle, &$n) {
            foreach ($chunk as $s) {
                fputcsv($handle, array_map([$this, 'safe'], [
                    $s->email, $s->first_name, $s->last_name, $s->status, $s->tags->pluck('name')->implode('|'), optional($s->created_at)->toIso8601String(),
                ]), ',', '"', '');
                $n++;
            }
        });

        return $n;
    }

    private function safe(?string $v): string
    {
        $v = (string) $v;

        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }
}
