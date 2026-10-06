<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;
use Taldres\LastSeen\Tests\TestModels\User;

it('checks if User model is an Eloquent Model class and implements Authenticatable contract', function () {
    $user = new User;
    expect($user)->toBeInstanceOf(Model::class)
        ->and($user)->toBeInstanceOf(Authenticatable::class);
});

it('checks if casts include last_seen_at and it is not made fillable', function () {
    $user = new (User::class);
    expect($user->getFillable())->not->toContain('last_seen_at')
        ->and($user->getCasts())->toHaveKey('last_seen_at')
        ->and($user->getCasts()['last_seen_at'])->toBe('datetime');
});

it('checks if last_seen_at is set to current time when user updates last seen', function () {
    $user = User::create(['email' => fake()->email()]);
    expect($user->last_seen_at)->toBeNull();

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at)->not->toBeNull()
        ->and($user->last_seen_at)->toBeInstanceOf(Carbon::class);
});

it('checks if recentlySeen returns true directly after setting', function () {
    $user = User::create(['email' => fake()->email()]);

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->recentlySeen())->toBeTrue();
});

it('checks if returns false for recentlySeen when last_seen_at is threshold+1 seconds in the past', function () {
    $user = User::forceCreate([
        'email' => fake()->email(),
        'last_seen_at' => now()->subSeconds(config('last-seen.recently_seen_threshold') + 1),
    ]);
    $user->refresh();

    expect($user->recentlySeen())->toBeFalse();
});

it('checks if updating should not be possible when the feature is disabled', function () {
    config(['last-seen.enabled' => false]);

    $user = User::create(['email' => fake()->email()]);

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at)->toBeNull();
});

it('checks if onlyRecentlySeen scope returns only recently seen users', function () {
    $recentUser = User::forceCreate([
        'email' => fake()->email(),
        'last_seen_at' => now(),
    ]);

    $staleUser = User::forceCreate([
        'email' => fake()->email(),
        'last_seen_at' => now()->subSeconds(config('last-seen.recently_seen_threshold') + 1),
    ]);

    $neverSeenUser = User::create([
        'email' => fake()->email(),
    ]);

    $recentUsers = User::onlyRecentlySeen()->get();

    expect($recentUsers)->toHaveCount(1)
        ->and($recentUsers->first()->id)->toBe($recentUser->id);
});

it('checks if updateLastSeenAt does not update when within threshold', function () {
    $initialTime = now()->subSeconds(10);

    $user = User::forceCreate([
        'email' => fake()->email(),
        'last_seen_at' => $initialTime,
    ]);

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at->timestamp)->toBe($initialTime->timestamp);
});

it('checks if updateLastSeenAt updates when threshold is exceeded', function () {
    $threshold = (int) config('last-seen.update_threshold');

    $user = User::forceCreate([
        'email' => fake()->email(),
        'last_seen_at' => now()->subSeconds($threshold + 1),
    ]);

    $this->travelTo(now());

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at->timestamp)->toBe(now()->timestamp);
});

it('checks if updateLastSeenAt does not touch the updated_at timestamp', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);
    $updatedAt = $user->updated_at;

    $this->travel((int) config('last-seen.update_threshold') + 1)->seconds();

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at)->not->toBeNull()
        ->and($user->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

it('checks if updateLastSeenAt does not persist other unsaved attributes', function () {
    $user = TimestampedUser::create(['email' => $email = fake()->email()]);
    $user->email = fake()->email();

    $user->updateLastSeenAt();

    expect($user->isDirty('last_seen_at'))->toBeFalse()
        ->and($user->isDirty('email'))->toBeTrue()
        ->and($user->fresh()->email)->toBe($email)
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});

it('checks if updateLastSeenAt does not write again when another instance already updated it', function () {
    $this->freezeSecond();

    $user = User::forceCreate([
        'email' => fake()->email(),
        'last_seen_at' => now()->subSeconds(config()->integer('last-seen.update_threshold') + 1),
    ]);

    $first = User::find($user->id);
    $second = User::find($user->id);

    $first->updateLastSeenAt();
    $this->travel(5)->seconds();
    $second->updateLastSeenAt();

    expect($user->fresh()->last_seen_at->timestamp)->toBe($first->last_seen_at->timestamp);
});

it('checks if recentlySeen and onlyRecentlySeen agree at the threshold boundary', function () {
    $this->freezeSecond();
    $threshold = config()->integer('last-seen.recently_seen_threshold');

    $atBoundary = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subSeconds($threshold)]);
    $pastBoundary = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subSeconds($threshold + 1)]);

    expect($atBoundary->fresh()->recentlySeen())->toBeTrue()
        ->and($pastBoundary->fresh()->recentlySeen())->toBeFalse()
        ->and(User::onlyRecentlySeen()->pluck('id')->all())->toBe([$atBoundary->id]);
});

it('checks if a last_seen_at in the future counts as recently seen in helper and scope', function () {
    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->addMinute()]);

    expect($user->fresh()->recentlySeen())->toBeTrue()
        ->and(User::onlyRecentlySeen()->pluck('id')->all())->toBe([$user->id]);
});
