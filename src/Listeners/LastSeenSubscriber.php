<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\LastSeenManager;

class LastSeenSubscriber
{
    public function __construct(private readonly LastSeenManager $lastSeen) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            UserWasActiveEvent::class,
            [self::class, 'handle']
        );
    }

    public function handle(UserWasActiveEvent $event): void
    {
        if ($event->user instanceof Model) {
            $this->lastSeen->record($event->user);
        }
    }
}
