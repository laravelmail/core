<?php

return [
    /*
    | SAFETY GATE (app issue #187): installing this package never sends mail.
    | Nothing is scheduled, no routes are registered, no processors run.
    | Every capability below is off until you switch it on explicitly.
    */
    'sending' => [
        // Master switch. While false, CampaignDispatcher refuses to queue or send anything.
        'enabled' => env('LARAVELMAIL_SENDING_ENABLED', false),
        // Laravel mailer name to use; null = host default mailer.
        'mailer' => env('LARAVELMAIL_MAILER'),
        'queue' => env('LARAVELMAIL_QUEUE', 'default'),
        'connection' => env('LARAVELMAIL_QUEUE_CONNECTION'),
    ],

    'routes' => [
        // Public HTTP endpoints (unsubscribe). Off by default.
        'enabled' => env('LARAVELMAIL_ROUTES_ENABLED', false),
        'prefix' => 'laravelmail',
        'middleware' => ['web'],
    ],

    // Table prefix, so we never collide with host tables.
    'table_prefix' => 'laravelmail_',

    // Run package migrations automatically? Default false: publish with `vendor:publish --tag=laravelmail-migrations`
    // or set true to load them in place.
    'load_migrations' => false,

    // Class implementing LaravelMail\Contracts\WorkspaceResolver. Default: single workspace (null id).
    'workspace_resolver' => \LaravelMail\Services\NullWorkspaceResolver::class,

    // Default sender, used when a campaign has none.
    'from' => [
        'address' => env('LARAVELMAIL_FROM_ADDRESS'),
        'name' => env('LARAVELMAIL_FROM_NAME'),
    ],
];
