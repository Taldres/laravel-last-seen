<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\LastSeenManager;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;

it('resolves the same manager through the facade and the container', function () {
    expect(LastSeen::getFacadeRoot())->toBe(app(LastSeenManager::class));
});

it('records activity only once within the update threshold', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and(LastSeen::record($user))->toBeFalse()
        ->and(LastSeen::recentlySeen($user))->toBeTrue()
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});

it('passes the user to the trackUsing callback', function () {
    $tracked = TimestampedUser::create(['email' => fake()->email()]);
    $untracked = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::trackUsing(fn (Model $user): bool => $user->is($tracked));

    expect(LastSeen::shouldTrack($tracked))->toBeTrue()
        ->and(LastSeen::shouldTrack($untracked))->toBeFalse()
        ->and(LastSeen::record($untracked))->toBeFalse()
        ->and($untracked->fresh()->last_seen_at)->toBeNull();
});

it('does not track when the feature is disabled', function () {
    config(['last-seen.enabled' => false]);

    expect(LastSeen::shouldTrack(TimestampedUser::create(['email' => fake()->email()])))->toBeFalse();
});

it('does not track models without the LastSeen trait', function () {
    $model = new class extends Model
    {
        protected $table = 'users';
    };

    expect(LastSeen::shouldTrack($model))->toBeFalse();
});

it('forgets the stored timestamp', function () {
    $user = TimestampedUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    LastSeen::forget($user);

    expect($user->last_seen_at)->toBeNull()
        ->and($user->fresh()->last_seen_at)->toBeNull()
        ->and(LastSeen::recentlySeen($user))->toBeFalse();
});
