<?php

use Illuminate\Support\Facades\Route;
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
