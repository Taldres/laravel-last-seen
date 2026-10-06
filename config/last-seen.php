<?php

declare(strict_types=1);

use App\Models\User;
use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;

return [
    'models' => [
        /*
         * The fully qualified class name of the User model that will be used to track the last seen timestamp.
         */
        'user' => User::class,
    ],

    /*
     * Feature flag to enable or disable the last seen functionality globally.
     * It will only affect updating the last seen timestamp.
     */
    'enabled' => (bool) env('LAST_SEEN_ENABLED', true),

    /*
     * The minimum number of seconds that must pass before the user's last_seen_at timestamp is updated again.
     * This helps to avoid excessive database writes when users are active.
     * Must be 0 or more. Default is 60 seconds.
     */
    'update_threshold' => (int) env('LAST_SEEN_UPDATE_THRESHOLD', LastSeenDefaultThreshold::Update->value),

    /*
     * The number of seconds a user is considered recently seen after their last activity.
     * A user counts as recently seen while last_seen_at is at most this many seconds ago.
     * Must be 0 or more. Default is 300 seconds (5 minutes).
     */
    'recently_seen_threshold' => (int) env('LAST_SEEN_RECENTLY_SEEN_THRESHOLD', LastSeenDefaultThreshold::RecentlySeen->value),
];
