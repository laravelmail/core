<?php

return [
    /*
    | SAFETY GATE (app issue #187): installing this package never sends mail.
    | Nothing is scheduled, no routes are registered, no processors run.
    | Every capability below is off until you switch it on explicitly.
    */
    // Feature flags. All default OFF. `sending` is the master delivery switch.
    'features' => [
        'sending' => env('LARAVELMAIL_SENDING_ENABLED', false),
        'api' => env('LARAVELMAIL_API_ENABLED', false), // token-authenticated JSON API; needs routes.enabled
        'deliverability' => env('LARAVELMAIL_DELIVERABILITY_ENABLED', false), // DB gateways, caps, warming, failover
        'tracking' => env('LARAVELMAIL_TRACKING_ENABLED', false), // needs routes.enabled
        'webhooks' => env('LARAVELMAIL_WEBHOOKS_ENABLED', false), // needs routes.enabled
        'automations' => env('LARAVELMAIL_AUTOMATIONS_ENABLED', false),
        'sms' => false,
        'forms' => env('LARAVELMAIL_FORMS_ENABLED', false), // public signup + preference center; needs routes.enabled
        'rss' => false,
        'scoring' => false,
        'ai' => false,
    ],

    // Email validation client. Disabled by default; no endpoint is hardcoded.
    'validation' => [
        'enabled' => env('LARAVELMAIL_VALIDATION_ENABLED', false),
        'url' => env('LARAVELMAIL_VALIDATION_URL'),
        'token' => env('LARAVELMAIL_VALIDATION_TOKEN'),
    ],

    'sending' => [
        // Named host mailer (null = host default), or an inline transport array passed to Mail::build().
        'mailer' => env('LARAVELMAIL_MAILER'),
        // Optional rotation pool: list of named mailers or inline transport arrays. Overrides `mailer` when non-empty.
        'pool' => [],
        'queue' => env('LARAVELMAIL_QUEUE', 'default'),
        'connection' => env('LARAVELMAIL_QUEUE_CONNECTION'),
    ],

    // Optional MJML support (needs node + `npm install` in resources/mjml). Falls back to raw HTML when absent.
    'mjml' => [
        'node' => env('MJML_NODE_BINARY'),
        'script' => null, // default: <package>/resources/mjml/compile.cjs
    ],

    // Provider webhook secrets (features.webhooks must be on). `allow_unsigned` is an emergency switch: keep false.
    'webhooks' => [
        'allow_unsigned' => false,
        'mailgun' => env('LARAVELMAIL_WEBHOOK_MAILGUN_KEY'),
        'postmark' => env('LARAVELMAIL_WEBHOOK_POSTMARK_TOKEN'),
        'sendgrid' => env('LARAVELMAIL_WEBHOOK_SENDGRID_PUBLIC_KEY'),
        'ses' => env('LARAVELMAIL_WEBHOOK_SES_TOPIC_ARNS'), // comma separated
    ],

    'deliverability' => [
        // DNSBL zones for blacklist monitoring. Empty = built-in defaults (spamhaus, spamcop, barracuda).
        'dnsbl_zones' => [],
    ],

    'routes' => [
        // Public HTTP endpoints (unsubscribe). Off by default.
        'enabled' => env('LARAVELMAIL_ROUTES_ENABLED', false),
        'prefix' => 'laravelmail',
        'middleware' => ['web'],
        'webhook_middleware' => ['api'],
        'api_throttle' => '120,1',
        'public_throttle' => '10,1', // per-IP limit for public signup (requests,minutes)
    ],

    // Table prefix, so we never collide with host tables.
    'table_prefix' => 'laravelmail_',

    // Run package migrations automatically? Default false: publish with `vendor:publish --tag=laravelmail-migrations`
    // or set true to load them in place.
    'load_migrations' => false,

    // Class implementing LaravelMail\Contracts\WorkspaceResolver. Default: single workspace (null id).
    'workspace_resolver' => \LaravelMail\Services\NullWorkspaceResolver::class,

    'user_resolver' => \LaravelMail\Services\NullUserResolver::class,

    // Default sender, used when a campaign has none.
    'from' => [
        'address' => env('LARAVELMAIL_FROM_ADDRESS'),
        'name' => env('LARAVELMAIL_FROM_NAME'),
    ],
];
