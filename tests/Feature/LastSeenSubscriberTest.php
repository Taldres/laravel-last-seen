<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\Tests\TestModels\User;

it('updates last_seen_at when the event is dispatched manually', function () {
    $user = User::create(['email' => fake()->email()]);

    Event::dispatch(new UserWasActiveEvent($user));

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('ignores users that do not use the LastSeen trait', function () {
    Event::dispatch(new UserWasActiveEvent(new GenericUser(['id' => 1])));
})->throwsNoExceptions();
