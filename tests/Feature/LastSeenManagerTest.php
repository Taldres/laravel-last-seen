<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
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
