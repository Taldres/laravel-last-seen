<?php

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Taldres\LastSeen\Tests\TestModels\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->timestamp('last_seen_at')->nullable();
    });
});

it('set last_seen_at to current time when user updates last seen', function () {
    $user = User::create(['email' => fake()->email]);
    expect($user->last_seen_at)->toBeNull();

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at)->not->toBeNull()
        ->and($user->last_seen_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('sets last_seen_at and recentlySeen returns true', function () {
    $user = User::create(['email' => fake()->email]);
    expect($user->last_seen_at)->toBeNull();

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->recentlySeen())->toBeTrue();
});

it('returns false for recentlySeen if last_seen_at is threshold+1 seconds in the past', function () {
    $user = User::create([
        'email' => fake()->email,
        'last_seen_at' => now()->subSeconds(config('last-seen.recently_seen_threshold') + 1),
    ]);
    $user->refresh();
    expect($user->recentlySeen())->toBeFalse();
});

it('updating should not be possible when the feature is disabled', function () {
    config(['last-seen.enabled' => false]);

    $user = User::create(['email' => fake()->email]);
    expect($user->last_seen_at)->toBeNull();

    $user->updateLastSeenAt();
    $user->refresh();

    expect($user->last_seen_at)->toBeNull();
});
