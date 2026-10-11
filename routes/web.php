<?php

use Illuminate\Support\Facades\Route;
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
