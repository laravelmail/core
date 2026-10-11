<?php

declare(strict_types=1);

namespace LaravelMail\Support;

/** Outbound URL guard (SSRF): https only, and the host must resolve only to public addresses. */
final class SafeUrl
{
    public static function isPublicHttps(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! str_starts_with(strtolower($url), 'https://') || ! is_string($host) || $host === '' || parse_url($url, PHP_URL_USER) !== null) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
