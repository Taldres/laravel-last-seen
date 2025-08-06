<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestModels\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->timestamp('last_seen_at')->nullable();
    });
});

it('checks if User model is an Eloquent Model class and implements Authenticatable contract', function () {
    $user = new User;
    expect($user)->toBeInstanceOf(Model::class)
        ->and($user)->toBeInstanceOf(Authenticatable::class);
});

it('checks if fillable and casts includes last_seen_at', function () {
    $user = new (User::class);
    expect($user->getFillable())->toContain('last_seen_at')
        ->and($user->getCasts())->toHaveKey('last_seen_at')
        ->and($user->getCasts()['last_seen_at'])->toBe('datetime');
});

it('checks if last_seen_at is set to current time when user updates last seen', function () {
    $user = User::create(['email' => fake()->email()]);
    expect($user->last_seen_at)->toBeNull();

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at)->not->toBeNull()
        ->and($user->last_seen_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('checks if recentlySeen returns true directly after setting', function () {
    $user = User::create(['email' => fake()->email()]);

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->recentlySeen())->toBeTrue();
});

it('checks if returns false for recentlySeen when last_seen_at is threshold+1 seconds in the past', function () {
    $user = User::create([
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
