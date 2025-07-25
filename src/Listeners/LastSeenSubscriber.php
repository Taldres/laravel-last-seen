<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Taldres\LastSeen\Events\UserSeenEvent;

class LastSeenSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            UserSeenEvent::class,
            [self::class, 'handle']
        );
    }

    public function handle(UserSeenEvent $event): void
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
