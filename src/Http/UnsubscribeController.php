<?php

declare(strict_types=1);

namespace LaravelMail\Http;

use LaravelMail\Models\Subscriber;

class UnsubscribeController
{
    public function __invoke(int $subscriber)
    {
        Subscriber::findOrFail($subscriber)->unsubscribe();

        return response('You have been unsubscribed.');
    }
}
