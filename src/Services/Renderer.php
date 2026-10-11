<?php

declare(strict_types=1);

namespace LaravelMail\Services;

use LaravelMail\Models\Campaign;
use LaravelMail\Models\Subscriber;

/** Renders campaign HTML: template wrapper ({{content}}) plus merge tags. No code execution. */
class Renderer
{
    public function render(Campaign $campaign, Subscriber $subscriber, ?\LaravelMail\Models\CampaignVariant $variant = null): string
    {
        $body = (string) ($variant?->content ?: $campaign->content);
        if ($campaign->template) {
            $body = str_contains($campaign->template->content, '{{content}}')
                ? str_replace('{{content}}', $body, $campaign->template->content)
                : $campaign->template->content;
        }

        return $this->merge($body, $subscriber);
    }

    public function renderSubject(Campaign $campaign, Subscriber $subscriber, ?\LaravelMail\Models\CampaignVariant $variant = null): string
    {
        return $this->merge((string) ($variant?->subject ?: $campaign->subject), $subscriber, false);
    }

    public function merge(string $text, Subscriber $subscriber, bool $escape = true): string
    {
        $vars = [
            'email' => $subscriber->email,
            'first_name' => (string) $subscriber->first_name,
            'last_name' => (string) $subscriber->last_name,
        ];

        return preg_replace_callback('/\{\{\s*(email|first_name|last_name)\s*\}\}/', function ($m) use ($vars, $escape) {
            $v = $vars[$m[1]];

            return $escape ? e($v) : $v;
        }, $text);
    }
}
