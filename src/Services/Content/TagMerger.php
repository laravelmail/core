<?php

declare(strict_types=1);

namespace LaravelMail\Services\Content;

use Illuminate\Support\Facades\Route;
use LaravelMail\Models\Message;

/** Merge tags: {{email}}, {{first_name}}, {{last_name}} (escaped by caller if HTML) and {{unsubscribe_url}}. */
class TagMerger
{
    public function merge(string $content, Message $message, bool $escape = true): string
    {
        $s = $message->subscriber;
        $vars = [
            'email' => (string) $message->recipient_email,
            'first_name' => (string) ($s->first_name ?? ''),
            'last_name' => (string) ($s->last_name ?? ''),
        ];
        $content = preg_replace_callback('/\{\{\s*(email|first_name|last_name)\s*\}\}/i', function ($m) use ($vars, $escape) {
            $v = $vars[strtolower($m[1])];

            return $escape ? e($v) : $v;
        }, $content);

        if ($s && Route::has('laravelmail.unsubscribe')) {
            $content = preg_replace('/\{\{\s*unsubscribe_url\s*\}\}/i', $s->unsubscribeUrl(), $content);
        }

        return $content;
    }
}
