<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\Subscribers\BulkActions;
use LaravelMail\Services\Subscribers\SubscriberExporter;
use LaravelMail\Services\Subscribers\SubscriberImporter;

class BulkSubscribersController
{
    /** CSV upload (field "file") or raw CSV in "csv". Header row required, see SubscriberImporter. Existing unsubscribed people stay unsubscribed. */
    public function import(Request $r, SubscriberImporter $importer)
    {
        $d = $r->validate(['file' => 'nullable|file|max:10240', 'csv' => 'nullable|string|max:5000000', 'tags' => 'array|max:20', 'tags.*' => 'string|max:100']);
        if (! $r->hasFile('file') && blank($d['csv'] ?? null)) {
            return response()->json(['message' => 'Send a CSV as "file" or "csv".'], 422);
        }
        if ($r->hasFile('file')) {
            $res = $importer->importFile($r->file('file')->getRealPath(), $d['tags'] ?? []);
        } else {
            $h = fopen('php://temp', 'r+');
            fwrite($h, $d['csv']);
            rewind($h);
            $res = $importer->import($h, $d['tags'] ?? []);
            fclose($h);
        }
        $res['errors'] = array_slice($res['errors'], 0, 50);

        return response()->json($res);
    }

    public function export(Request $r, SubscriberExporter $exporter)
    {
        $q = Subscriber::query();
        if ($r->filled('status')) {
            $q->where('status', $r->string('status'));
        }
        if ($r->filled('tag')) {
            $q->whereHas('tags', fn ($t) => $t->where((new \LaravelMail\Models\Tag)->getTable().'.name', $r->string('tag')));
        }

        return response()->streamDownload(function () use ($exporter, $q) {
            $out = fopen('php://output', 'w');
            $exporter->write($out, $q);
            fclose($out);
        }, 'subscribers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** body: {action, ids|tag|status|email_contains, tag_name?, dry_run?}. dry_run reports the count without changing anything. */
    public function bulk(Request $r, BulkActions $bulk)
    {
        $d = $r->validate([
            'action' => 'required|in:'.implode(',', BulkActions::ACTIONS), 'ids' => 'array|max:10000', 'ids.*' => 'integer',
            'tag' => 'nullable|string|max:100', 'status' => 'nullable|in:subscribed,unsubscribed', 'email_contains' => 'nullable|string|max:255',
            'tag_name' => 'nullable|string|max:100', 'dry_run' => 'boolean',
        ]);
        try {
            $n = $bulk->run($d['action'], array_intersect_key($d, array_flip(['ids', 'tag', 'status', 'email_contains'])), $d['tag_name'] ?? null, (bool) ($d['dry_run'] ?? false));
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return ['action' => $d['action'], 'dry_run' => (bool) ($d['dry_run'] ?? false), 'affected' => $n];
    }
}
