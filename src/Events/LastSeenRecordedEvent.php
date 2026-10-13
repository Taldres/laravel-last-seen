<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Events;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when last_seen_at was actually written, in contrast to UserWasActiveEvent, which fires for every
 * request. $previousLastSeenAt is the value the model held before the write. $cameOnline is true when the
 * user was not recently seen before, which includes users who were never seen and models that were just
 * created. When the model was loaded without last_seen_at, or a cast does not return a date object, the
 * previous value is unknown: it is null and $cameOnline is false.
 */
class LastSeenRecordedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $user,
        public readonly ?CarbonInterface $previousLastSeenAt,
        public readonly bool $cameOnline,
    ) {}
}
