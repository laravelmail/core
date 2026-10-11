<?php

declare(strict_types=1);

namespace LaravelMail\Services\Content;

class PixelInjector
{
    public function inject(string $content, string $url): string
    {
        $pixel = '<img src="'.e($url).'" width="1" height="1" style="display:none;" alt="">';

        return stripos($content, '</body>') !== false
            ? str_ireplace('</body>', $pixel.'</body>', $content)
            : $content.$pixel;
    }
}
