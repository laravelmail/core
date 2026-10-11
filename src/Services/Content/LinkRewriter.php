<?php

declare(strict_types=1);

namespace LaravelMail\Services\Content;

use LaravelMail\Models\Message;
use LaravelMail\Models\MessageUrl;

/** Rewrites absolute http(s) hrefs to tracked redirects. Unsubscribe/webview style links are left alone. */
class LinkRewriter
{
    public function rewrite(string $content, Message $message): string
    {
        return preg_replace_callback('/href=(["\'])(https?:\/\/[^"\']+)\1/i', function ($m) use ($message) {
            $url = html_entity_decode($m[2]);
            if (str_contains($url, '/laravelmail/unsubscribe/') || str_contains($url, '/laravelmail/t/')) {
                return $m[0];
            }
            $hash = md5($message->id.'_'.$url);
            MessageUrl::firstOrCreate(['message_id' => $message->id, 'hash' => $hash], ['url' => $url]);

            return 'href="'.route('laravelmail.track.click', ['message' => $message->hash, 'url' => $hash]).'"';
        }, $content);
    }
}
