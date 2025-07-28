<?php

declare(strict_types=1);

use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;

return [
    'models' => [
        /*
         * The fully qualified class name of the User model that will be used to track the last seen timestamp.
         */
        'user' => App\Models\User::class,
    ],

    /*
     * Feature flag to enable or disable the last seen functionality globally.
     * It will only affect updating the last seen timestamp.
     */
    'enabled' => (bool) env('LAST_SEEN_ENABLED', true),

    /*
     * The minimum number of seconds that must pass before the user's last_seen_at timestamp is updated again.
     * This helps to avoid excessive database writes when users are active.
     * Default is 60 seconds.
     */
    'update_threshold' => (int) env('LAST_SEEN_UPDATE_THRESHOLD', LastSeenDefaultThreshold::Update->value),

    /*
     * The number of seconds a user is considered recently seen after their last activity.
     * If the difference between now and last_seen_at is less than this value, the user is considered recently active.
     * Default is 300 seconds (5 minutes).
     */
    'recently_seen_threshold' => (int) env('LAST_SEEN_RECENTLY_SEEN_THRESHOLD', LastSeenDefaultThreshold::RecentlySeen->value),
];
