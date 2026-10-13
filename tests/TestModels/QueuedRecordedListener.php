<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Contracts\Queue\ShouldQueue;
use Taldres\LastSeen\Events\LastSeenRecordedEvent;

class QueuedRecordedListener implements ShouldQueue
{
    /**
     * @var list<LastSeenRecordedEvent>
     */
    public static array $events = [];

    public function shouldQueue(LastSeenRecordedEvent $event): bool
    {
        return $event->becameRecentlySeen;
    }

    public function handle(LastSeenRecordedEvent $event): void
    {
        self::$events[] = $event;
    }
}
