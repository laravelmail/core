<?php

use Illuminate\Support\Facades\Route;
use LaravelMail\Http\Api;
use LaravelMail\Http\PreferencesController;
use LaravelMail\Http\PublicSubscribeController;
use LaravelMail\Http\TrackingController;
use LaravelMail\Http\UnsubscribeController;
use LaravelMail\Http\WebhookController;

Route::group([
    'prefix' => config('laravelmail.routes.prefix'),
    'middleware' => config('laravelmail.routes.middleware'),
    'as' => 'laravelmail.',
], function () {
    // Signed URLs only: see Subscriber::unsubscribeUrl().
    Route::get('unsubscribe/{subscriber}', UnsubscribeController::class)->middleware('signed')->name('unsubscribe');

    // Preference center (JSON, signed). Off unless features.forms is on.
    if (config('laravelmail.features.forms')) {
        Route::get('preferences/{subscriber}', [PreferencesController::class, 'show'])->middleware('signed')->name('preferences.show');
        Route::post('preferences/{subscriber}', [PreferencesController::class, 'update'])->middleware('signed')->name('preferences.update');
        Route::post('preferences/{subscriber}/resubscribe', [PreferencesController::class, 'resubscribe'])->middleware('signed')->name('preferences.resubscribe');
        Route::get('subscribe/confirm/{token}', [PublicSubscribeController::class, 'confirm'])->name('subscribe.confirm');
    }

    // Open/click tracking endpoints exist only when features.tracking is on.
    if (config('laravelmail.features.tracking')) {
        Route::get('t/o/{message}', [TrackingController::class, 'open'])->name('track.open');
        Route::get('t/c/{message}/{url}', [TrackingController::class, 'click'])->name('track.click');
    }
});

// Provider webhooks: only when features.webhooks is on, stateless middleware (no CSRF), always authenticated.
if (config('laravelmail.features.webhooks')) {
    Route::group([
        'prefix' => config('laravelmail.routes.prefix'),
        'middleware' => config('laravelmail.routes.webhook_middleware'),
        'as' => 'laravelmail.',
    ], function () {
        Route::post('webhooks/{provider}', WebhookController::class)->name('webhooks');
    });
}

// Public signup endpoint: only with features.forms, stateless, throttled.
if (config('laravelmail.features.forms')) {
    Route::group([
        'prefix' => config('laravelmail.routes.prefix'),
        'middleware' => [...config('laravelmail.routes.webhook_middleware'), 'throttle:'.config('laravelmail.routes.public_throttle')],
        'as' => 'laravelmail.',
    ], function () {
        Route::post('subscribe/{uuid}', [PublicSubscribeController::class, 'subscribe'])->name('subscribe');
    });
}

// Token-authenticated JSON API: only when features.api is on. Stateless, throttled, scoped per ability.
if (config('laravelmail.features.api')) {
    Route::group([
        'prefix' => config('laravelmail.routes.prefix').'/api/v1',
        'middleware' => [...config('laravelmail.routes.webhook_middleware'), 'throttle:'.config('laravelmail.routes.api_throttle')],
        'as' => 'laravelmail.api.',
    ], function () {
        $a = fn (string $ability) => 'laravelmail.token:'.$ability;

        // Fixed paths before subscribers/{id}.
        Route::post('subscribers/import', [Api\BulkSubscribersController::class, 'import'])->middleware($a('subscribers:write'))->name('subscribers.import');
        Route::get('subscribers/export', [Api\BulkSubscribersController::class, 'export'])->middleware($a('subscribers:read'))->name('subscribers.export');
        Route::post('subscribers/bulk', [Api\BulkSubscribersController::class, 'bulk'])->middleware($a('subscribers:write'))->name('subscribers.bulk');
        Route::get('subscribers', [Api\SubscribersController::class, 'index'])->middleware($a('subscribers:read'))->name('subscribers.index');
        Route::post('subscribers', [Api\SubscribersController::class, 'store'])->middleware($a('subscribers:write'))->name('subscribers.store');
        Route::get('subscribers/{id}', [Api\SubscribersController::class, 'show'])->middleware($a('subscribers:read'))->whereNumber('id')->name('subscribers.show');
        Route::patch('subscribers/{id}', [Api\SubscribersController::class, 'update'])->middleware($a('subscribers:write'))->whereNumber('id')->name('subscribers.update');
        Route::delete('subscribers/{id}', [Api\SubscribersController::class, 'destroy'])->middleware($a('subscribers:write'))->whereNumber('id')->name('subscribers.destroy');
        Route::post('subscribers/{id}/tags', [Api\SubscribersController::class, 'attachTags'])->middleware($a('subscribers:write'))->whereNumber('id')->name('subscribers.tags.attach');
        Route::delete('subscribers/{id}/tags', [Api\SubscribersController::class, 'detachTags'])->middleware($a('subscribers:write'))->whereNumber('id')->name('subscribers.tags.detach');

        Route::get('tags', [Api\TagsController::class, 'index'])->middleware($a('tags:read'))->name('tags.index');
        Route::post('tags', [Api\TagsController::class, 'store'])->middleware($a('tags:write'))->name('tags.store');
        Route::delete('tags/{id}', [Api\TagsController::class, 'destroy'])->middleware($a('tags:write'))->whereNumber('id')->name('tags.destroy');

        Route::get('segments', [Api\SegmentsController::class, 'index'])->middleware($a('segments:read'))->name('segments.index');
        Route::post('segments', [Api\SegmentsController::class, 'store'])->middleware($a('segments:write'))->name('segments.store');
        Route::get('segments/{id}', [Api\SegmentsController::class, 'show'])->middleware($a('segments:read'))->whereNumber('id')->name('segments.show');
        Route::put('segments/{id}', [Api\SegmentsController::class, 'update'])->middleware($a('segments:write'))->whereNumber('id')->name('segments.update');
        Route::delete('segments/{id}', [Api\SegmentsController::class, 'destroy'])->middleware($a('segments:write'))->whereNumber('id')->name('segments.destroy');

        Route::get('templates', [Api\TemplatesController::class, 'index'])->middleware($a('templates:read'))->name('templates.index');
        Route::post('templates', [Api\TemplatesController::class, 'store'])->middleware($a('templates:write'))->name('templates.store');
        Route::get('templates/{id}', [Api\TemplatesController::class, 'show'])->middleware($a('templates:read'))->whereNumber('id')->name('templates.show');
        Route::put('templates/{id}', [Api\TemplatesController::class, 'update'])->middleware($a('templates:write'))->whereNumber('id')->name('templates.update');
        Route::delete('templates/{id}', [Api\TemplatesController::class, 'destroy'])->middleware($a('templates:write'))->whereNumber('id')->name('templates.destroy');

        Route::get('campaigns', [Api\CampaignsController::class, 'index'])->middleware($a('campaigns:read'))->name('campaigns.index');
        Route::post('campaigns', [Api\CampaignsController::class, 'store'])->middleware($a('campaigns:write'))->name('campaigns.store');
        Route::get('campaigns/{id}', [Api\CampaignsController::class, 'show'])->middleware($a('campaigns:read'))->whereNumber('id')->name('campaigns.show');
        Route::patch('campaigns/{id}', [Api\CampaignsController::class, 'update'])->middleware($a('campaigns:write'))->whereNumber('id')->name('campaigns.update');
        Route::delete('campaigns/{id}', [Api\CampaignsController::class, 'destroy'])->middleware($a('campaigns:write'))->whereNumber('id')->name('campaigns.destroy');
        Route::put('campaigns/{id}/variants', [Api\CampaignsController::class, 'setVariants'])->middleware($a('campaigns:write'))->whereNumber('id')->name('campaigns.variants');
        Route::get('campaigns/{id}/variants/results', [Api\CampaignsController::class, 'results'])->middleware($a('campaigns:read'))->whereNumber('id')->name('campaigns.variants.results');
        Route::get('campaigns/{id}/audience', [Api\CampaignsController::class, 'audience'])->middleware($a('campaigns:read'))->whereNumber('id')->name('campaigns.audience');
        Route::post('campaigns/{id}/dispatch', [Api\CampaignsController::class, 'dispatch'])->middleware($a('campaigns:send'))->whereNumber('id')->name('campaigns.dispatch');

        // Deliverability: IP pools and blacklist monitoring. Needs features.deliverability as well.
        if (config('laravelmail.features.deliverability')) {
            Route::get('ip-pools', [Api\IpPoolsController::class, 'pools'])->middleware($a('deliverability:read'))->name('ip-pools.index');
            Route::post('ip-pools', [Api\IpPoolsController::class, 'storePool'])->middleware($a('deliverability:write'))->name('ip-pools.store');
            Route::delete('ip-pools/{id}', [Api\IpPoolsController::class, 'destroyPool'])->middleware($a('deliverability:write'))->whereNumber('id')->name('ip-pools.destroy');
            Route::get('ips', [Api\IpPoolsController::class, 'ips'])->middleware($a('deliverability:read'))->name('ips.index');
            Route::post('ips', [Api\IpPoolsController::class, 'storeIp'])->middleware($a('deliverability:write'))->name('ips.store');
            Route::patch('ips/{id}', [Api\IpPoolsController::class, 'updateIp'])->middleware($a('deliverability:write'))->whereNumber('id')->name('ips.update');
            Route::delete('ips/{id}', [Api\IpPoolsController::class, 'destroyIp'])->middleware($a('deliverability:write'))->whereNumber('id')->name('ips.destroy');
            Route::post('ips/{id}/check', [Api\IpPoolsController::class, 'check'])->middleware($a('deliverability:write'))->whereNumber('id')->name('ips.check');
            Route::get('ips/{id}/history', [Api\IpPoolsController::class, 'history'])->middleware($a('deliverability:read'))->whereNumber('id')->name('ips.history');
        }

        // Lead scoring (needs features.scoring too).
        if (config('laravelmail.features.scoring')) {
            Route::get('scoring-rules', [Api\ScoringController::class, 'index'])->middleware($a('scoring:read'))->name('scoring.index');
            Route::post('scoring-rules', [Api\ScoringController::class, 'store'])->middleware($a('scoring:write'))->name('scoring.store');
            Route::put('scoring-rules/{id}', [Api\ScoringController::class, 'update'])->middleware($a('scoring:write'))->whereNumber('id')->name('scoring.update');
            Route::delete('scoring-rules/{id}', [Api\ScoringController::class, 'destroy'])->middleware($a('scoring:write'))->whereNumber('id')->name('scoring.destroy');
            Route::get('subscribers/{id}/score-events', [Api\ScoringController::class, 'events'])->middleware($a('scoring:read'))->whereNumber('id')->name('scoring.events');
            Route::post('subscribers/{id}/score-events', [Api\ScoringController::class, 'custom'])->middleware($a('scoring:write'))->whereNumber('id')->name('scoring.custom');
        }

        // RSS (needs features.rss too).
        if (config('laravelmail.features.rss')) {
            Route::get('rss-feeds', [Api\RssController::class, 'index'])->middleware($a('rss:read'))->name('rss.index');
            Route::post('rss-feeds', [Api\RssController::class, 'store'])->middleware($a('rss:write'))->name('rss.store');
            Route::delete('rss-feeds/{id}', [Api\RssController::class, 'destroy'])->middleware($a('rss:write'))->whereNumber('id')->name('rss.destroy');
            Route::get('rss-feeds/{id}/items', [Api\RssController::class, 'items'])->middleware($a('rss:read'))->whereNumber('id')->name('rss.items');
            Route::post('rss-feeds/{id}/fetch', [Api\RssController::class, 'fetch'])->middleware($a('rss:write'))->whereNumber('id')->name('rss.fetch');
            Route::post('rss-feeds/{id}/draft-campaign', [Api\RssController::class, 'draft'])->middleware($a('rss:write'))->whereNumber('id')->name('rss.draft');
        }
    });
}
