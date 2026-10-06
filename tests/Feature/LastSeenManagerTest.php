<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Tests\TestModels\User;

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
