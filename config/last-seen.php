<?php

declare(strict_types=1);

return [
    // Feature flag to enable or disable the last seen functionality globally.
    // Set LAST_SEEN_ENABLED in your .env file to true or false. Default is true.
    'enabled' => (bool) env('LAST_SEEN_ENABLED', true),

    // The fully qualified class name of the User model that will be used to track the last seen timestamp.
    'user_model' => (string) env('LAST_SEEN_USER_MODEL', App\Models\User::class),

    // The minimum number of seconds that must pass before the user's last_seen timestamp is updated again.
    // This helps to avoid excessive database writes when users are active.
    'update_threshold' => (int) env('LAST_SEEN_UPDATE_THRESHOLD', 60),

    // The number of seconds a user is considered recently seen after their last activity.
    // If the difference between now and last_seen is less than this value, the user is considered recently active.
    'recently_seen_threshold' => (int) env('LAST_SEEN_RECENTLY_SEEN_THRESHOLD', 300),
];
