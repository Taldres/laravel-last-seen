<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Taldres\LastSeen\Events\UserWasActiveEvent;

class LastSeenSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            UserWasActiveEvent::class,
            [self::class, 'handle']
        );
    }

    public function handle(UserWasActiveEvent $event): void
    {
        if (! config('last-seen.enabled', true)) {
            return;
        }

        if (! method_exists($event->user, 'updateLastSeen')) {
            return;
        }

        $event->user->updateLastSeen();
    }
}
