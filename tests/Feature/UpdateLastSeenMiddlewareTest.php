<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;

beforeEach(function () {
    Route::get('/last-seen', fn () => 'ok')->middleware(UpdateLastSeenMiddleware::class);
});

it('updates last_seen_at for an authenticated user', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($user)->get('/last-seen')->assertOk();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('does not dispatch the event for guests', function () {
    Event::fake([UserWasActiveEvent::class]);

    $this->get('/last-seen')->assertOk();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('does not dispatch the event when the feature is disabled', function () {
    Event::fake([UserWasActiveEvent::class]);
    config(['last-seen.enabled' => false]);

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('ignores authenticated users that do not use the LastSeen trait', function () {
    Event::fake([UserWasActiveEvent::class]);

    $this->actingAs(new GenericUser(['id' => 1]))->get('/last-seen')->assertOk();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('updates last_seen_at when the authentication middleware runs after it', function () {
    Auth::viaRequest('last-seen-token', fn (Request $request) => TimestampedUser::find($request->header('X-User-Id')));
    config(['auth.guards.last-seen-token' => ['driver' => 'last-seen-token']]);

    Route::get('/last-seen-token', fn () => 'ok')
        ->middleware([UpdateLastSeenMiddleware::class, 'auth:last-seen-token']);

    $user = TimestampedUser::create(['email' => fake()->email()]);

    $this->get('/last-seen-token', ['X-User-Id' => (string) $user->id])->assertOk();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});
