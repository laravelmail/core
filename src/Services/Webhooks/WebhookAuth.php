<?php

declare(strict_types=1);

namespace LaravelMail\Services\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Authenticates provider webhooks against secrets in laravelmail.webhooks.*. Ported from the app, minus per-service secrets. */
class WebhookAuth
{
    public function allowUnsigned(): bool
    {
        return (bool) config('laravelmail.webhooks.allow_unsigned', false);
    }

    public function secret(string $provider): ?string
    {
        $v = config('laravelmail.webhooks.'.$provider);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    public static function pem(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN PUBLIC KEY')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split((string) preg_replace('/\s+/', '', $key), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public function sendgrid(Request $request, string $publicKey): bool
    {
        $sig = base64_decode((string) $request->header('X-Twilio-Email-Event-Webhook-Signature'), true);
        $ts = (string) $request->header('X-Twilio-Email-Event-Webhook-Timestamp');
        if (! $sig || $ts === '') {
            return false;
        }

        return @openssl_verify($ts.$request->getContent(), $sig, self::pem($publicKey), OPENSSL_ALGO_SHA256) === 1;
    }

    /** Postmark has no signature: token as basic-auth password or ?token=. */
    public function token(Request $request, string $token): bool
    {
        $given = (string) ($request->getPassword() ?? '');
        if ($given === '') {
            $given = (string) $request->query('token', '');
        }

        return $given !== '' && hash_equals($token, $given);
    }

    public function mailgun(array $payload, string $signingKey): bool
    {
        $s = $payload['signature'] ?? null;
        if (! is_array($s) || ! isset($s['timestamp'], $s['token'], $s['signature'])) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $s['timestamp'].$s['token'], $signingKey), (string) $s['signature']);
    }

    /** SNS message signature (v1/v2); the certificate is fetched from AWS hosts only. */
    public function snsSignatureValid(array $payload): bool
    {
        $url = (string) ($payload['SigningCertURL'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host)
            || ! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host)
            || ! str_ends_with((string) parse_url($url, PHP_URL_PATH), '.pem')) {
            return false;
        }
        $fields = ($payload['Type'] ?? '') === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $string = '';
        foreach ($fields as $f) {
            if (array_key_exists($f, $payload)) {
                $string .= $f."\n".$payload[$f]."\n";
            }
        }
        $cert = Cache::remember('laravelmail-sns-cert:'.sha1($url), now()->addDay(), function () use ($url) {
            $r = Http::timeout(10)->get($url);

            return $r->successful() ? $r->body() : null;
        });
        $sig = base64_decode((string) ($payload['Signature'] ?? ''), true);
        if (! $cert || ! $sig) {
            return false;
        }

        return @openssl_verify($string, $sig, $cert, (string) ($payload['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) === 1;
    }
}
