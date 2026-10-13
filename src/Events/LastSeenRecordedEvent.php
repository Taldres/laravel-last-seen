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
 * request. $previousLastSeenAt is the value the model held before the write. $becameRecentlySeen is true when the
 * user was not recently seen before, which includes users who were never seen and models that were just
 * created. When the model was loaded without last_seen_at, or a cast does not return a date object, the
 * previous value is unknown: it is null and $becameRecentlySeen is false. $recordedAt is the value that was
 * written, as a freshly loaded model reads it. It stays the same when a queued listener reloads the user.
 */
class LastSeenRecordedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly CarbonInterface $recordedAt;

    public function __construct(
        public readonly Model $user,
        public readonly ?CarbonInterface $previousLastSeenAt,
        public readonly bool $becameRecentlySeen,
        ?CarbonInterface $recordedAt = null,
    ) {
        $this->recordedAt = $recordedAt ?? now();
    }
}
