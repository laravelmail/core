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

        Route::get('subscribers', [Api\SubscribersController::class, 'index'])->middleware($a('subscribers:read'))->name('subscribers.index');
        Route::post('subscribers', [Api\SubscribersController::class, 'store'])->middleware($a('subscribers:write'))->name('subscribers.store');
        Route::get('subscribers/{id}', [Api\SubscribersController::class, 'show'])->middleware($a('subscribers:read'))->name('subscribers.show');
        Route::patch('subscribers/{id}', [Api\SubscribersController::class, 'update'])->middleware($a('subscribers:write'))->name('subscribers.update');
        Route::delete('subscribers/{id}', [Api\SubscribersController::class, 'destroy'])->middleware($a('subscribers:write'))->name('subscribers.destroy');
        Route::post('subscribers/{id}/tags', [Api\SubscribersController::class, 'attachTags'])->middleware($a('subscribers:write'))->name('subscribers.tags.attach');
        Route::delete('subscribers/{id}/tags', [Api\SubscribersController::class, 'detachTags'])->middleware($a('subscribers:write'))->name('subscribers.tags.detach');

        Route::get('tags', [Api\TagsController::class, 'index'])->middleware($a('tags:read'))->name('tags.index');
        Route::post('tags', [Api\TagsController::class, 'store'])->middleware($a('tags:write'))->name('tags.store');
        Route::delete('tags/{id}', [Api\TagsController::class, 'destroy'])->middleware($a('tags:write'))->name('tags.destroy');

        Route::get('segments', [Api\SegmentsController::class, 'index'])->middleware($a('segments:read'))->name('segments.index');
        Route::post('segments', [Api\SegmentsController::class, 'store'])->middleware($a('segments:write'))->name('segments.store');
        Route::get('segments/{id}', [Api\SegmentsController::class, 'show'])->middleware($a('segments:read'))->name('segments.show');
        Route::put('segments/{id}', [Api\SegmentsController::class, 'update'])->middleware($a('segments:write'))->name('segments.update');
        Route::delete('segments/{id}', [Api\SegmentsController::class, 'destroy'])->middleware($a('segments:write'))->name('segments.destroy');

        Route::get('templates', [Api\TemplatesController::class, 'index'])->middleware($a('templates:read'))->name('templates.index');
        Route::post('templates', [Api\TemplatesController::class, 'store'])->middleware($a('templates:write'))->name('templates.store');
        Route::get('templates/{id}', [Api\TemplatesController::class, 'show'])->middleware($a('templates:read'))->name('templates.show');
        Route::put('templates/{id}', [Api\TemplatesController::class, 'update'])->middleware($a('templates:write'))->name('templates.update');
        Route::delete('templates/{id}', [Api\TemplatesController::class, 'destroy'])->middleware($a('templates:write'))->name('templates.destroy');

        Route::get('campaigns', [Api\CampaignsController::class, 'index'])->middleware($a('campaigns:read'))->name('campaigns.index');
        Route::post('campaigns', [Api\CampaignsController::class, 'store'])->middleware($a('campaigns:write'))->name('campaigns.store');
        Route::get('campaigns/{id}', [Api\CampaignsController::class, 'show'])->middleware($a('campaigns:read'))->name('campaigns.show');
        Route::patch('campaigns/{id}', [Api\CampaignsController::class, 'update'])->middleware($a('campaigns:write'))->name('campaigns.update');
        Route::delete('campaigns/{id}', [Api\CampaignsController::class, 'destroy'])->middleware($a('campaigns:write'))->name('campaigns.destroy');
        Route::get('campaigns/{id}/audience', [Api\CampaignsController::class, 'audience'])->middleware($a('campaigns:read'))->name('campaigns.audience');
        Route::post('campaigns/{id}/dispatch', [Api\CampaignsController::class, 'dispatch'])->middleware($a('campaigns:send'))->name('campaigns.dispatch');
    });
}
