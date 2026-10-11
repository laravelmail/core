<?php

declare(strict_types=1);

namespace LaravelMail\Services\Templates;

/** Wraps an HTML email as one <mj-raw> block (no reliable HTML -> MJML conversion exists). Scripts are stripped. */
class HtmlToMjml
{
    public static function wrap(string $html): string
    {
        $styles = '';
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $m)) {
            $styles = trim(implode("\n", $m[1]));
        }
        $body = preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $b) ? $b[1] : $html;
        $body = preg_replace('#<script\b.*?</script>#is', '', $body);
        $body = str_ireplace('</mj-raw>', '', $body);
        $head = $styles !== '' ? "  <mj-head>\n    <mj-style>".str_ireplace('</mj-style>', '', $styles)."</mj-style>\n  </mj-head>\n" : '';

        return "<mjml>\n{$head}  <mj-body>\n    <mj-raw>".trim($body)."</mj-raw>\n  </mj-body>\n</mjml>";
    }
}
