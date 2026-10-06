<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Tests\TestModels\UnixTimestampUser;
use Taldres\LastSeen\Tests\TestModels\User;
use Taldres\LastSeen\Tests\TestModels\UtcStoredUser;

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('records the row the model was loaded from when its key was changed in memory', function () {
    $user = User::create(['email' => fake()->email()]);
    $other = User::create(['email' => fake()->email()]);

    $user->id = $other->id;

    expect(LastSeen::record($user))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->getOriginal('id'))->value('last_seen_at'))->not->toBeNull()
        ->and($other->fresh()->last_seen_at)->toBeNull();
});

it('forgets the row the model was loaded from when its key was changed in memory', function () {
    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $other = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    $user->id = $other->id;
    LastSeen::forget($user);

    expect(DB::table('users')->where('id', $user->getOriginal('id'))->value('last_seen_at'))->toBeNull()
        ->and($other->fresh()->last_seen_at)->not->toBeNull();
});

it('records a model loaded without last_seen_at in strict mode', function () {
    $user = User::create(['email' => fake()->email()]);
    $partial = fn () => User::query()->select(['id', 'email'])->findOrFail($user->id);

    Model::preventAccessingMissingAttributes();

    try {
        expect(LastSeen::record($partial()))->toBeTrue()
            ->and($user->fresh()->last_seen_at)->not->toBeNull()
            ->and(fn () => $partial()->recentlySeen())->toThrow(MissingAttributeException::class);
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});

it('records once the update threshold has passed, for loaded and partially selected models alike', function (int $elapsed, bool $written) {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00')->addSeconds($elapsed));

    $loaded = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:00:00'])->fresh();
    $partial = User::query()->select(['id', 'email'])->findOrFail(
        User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:00:00'])->id,
    );

    expect(LastSeen::record($loaded))->toBe($written)
        ->and(LastSeen::record($partial))->toBe($written);
})->with([
    'one second before the threshold' => [59, false],
    'exactly at the threshold' => [60, true],
    'one second after the threshold' => [61, true],
]);

it('calls the trackUsing callback only when a write is due', function () {
    $calls = 0;
    LastSeen::trackUsing(function () use (&$calls) {
        $calls++;

        return true;
    });

    $user = User::create(['email' => fake()->email()]);

    LastSeen::record($user);
    LastSeen::record($user);
    LastSeen::record($user);

    expect($calls)->toBe(1);
});

it('replaces a last_seen_at in the future', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $loaded = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-07 12:00:00'])->fresh();
    $partial = User::query()->select(['id', 'email'])->findOrFail(
        User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-07 12:00:00'])->id,
    );

    expect(LastSeen::record($loaded))->toBeTrue()
        ->and($loaded->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 12:00:00')
        ->and(LastSeen::record($partial))->toBeTrue();
});

it('stores last_seen_at the way Eloquent saves it, also through date formats and casts', function (string $model, string $timezone) {
    date_default_timezone_set($timezone);
    $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

    $recorded = $model::create(['email' => fake()->email()]);
    $saved = $model::create(['email' => fake()->email()])->forceFill(['last_seen_at' => now()]);
    $saved->save();

    expect(LastSeen::record($recorded))->toBeTrue()
        ->and(DB::table('users')->where('id', $recorded->id)->value('last_seen_at'))
        ->toBe(DB::table('users')->where('id', $saved->id)->value('last_seen_at'))
        ->and(LastSeen::record($recorded->fresh()))->toBeFalse();
})->with([
    'unix timestamp date format' => [UnixTimestampUser::class, 'UTC'],
    'cast that stores UTC in a non-UTC app' => [UtcStoredUser::class, 'Europe/Berlin'],
]);

it('refuses to forget last_seen_at of a model loaded without its key', function () {
    $user = User::forceCreate(['email' => $email = fake()->email(), 'last_seen_at' => now()]);
    $keyless = User::query()->select(['email', 'last_seen_at'])->where('email', $email)->firstOrFail();

    expect(fn () => LastSeen::forget($keyless))->toThrow(LogicException::class)
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});
