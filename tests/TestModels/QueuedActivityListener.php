<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Events\UserWasActiveEvent;

class QueuedActivityListener implements ShouldQueue
{
    /**
     * @var list<Model|Authenticatable>
     */
    public static array $users = [];

    public function handle(UserWasActiveEvent $event): void
    {
        self::$users[] = $event->user;
    }
}
