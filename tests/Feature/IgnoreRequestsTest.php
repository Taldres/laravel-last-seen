<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;

beforeEach(function () {
    Route::get('/last-seen', fn () => 'ok')->middleware(UpdateLastSeenMiddleware::class);
    Route::get('/notifications/poll', fn () => 'poll')->middleware(UpdateLastSeenMiddleware::class);
});

it('leaves out the requests the callback rejects and counts all others', function () {
    LastSeen::ignoreRequestsUsing(fn (Request $request): bool => $request->is('notifications/poll'));

    $polling = TimestampedUser::create(['email' => fake()->email()]);
    $working = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($polling)->get('/notifications/poll')->assertOk()->assertSee('poll');
    $this->actingAs($working)->get('/last-seen')->assertOk()->assertSee('ok');

    expect($polling->fresh()->last_seen_at)->toBeNull()
        ->and($working->fresh()->last_seen_at)->not->toBeNull();
});

it('neither resolves the user nor asks trackUsing or fires an event for an ignored request', function (bool $ignored) {
    $user = TimestampedUser::create(['email' => fake()->email()]);
    $resolved = 0;
    Auth::viaRequest('counting', function () use (&$resolved, $user) {
        $resolved++;

        return $user;
    });
    config(['auth.guards.counting' => ['driver' => 'counting'], 'auth.defaults.guard' => 'counting']);

    $tracked = 0;
    LastSeen::trackUsing(function () use (&$tracked) {
        $tracked++;

        return true;
    });
    LastSeen::ignoreRequestsUsing(fn (): bool => $ignored);
    Event::fake([UserWasActiveEvent::class]);

    $this->get('/last-seen')->assertOk();

    expect($resolved > 0)->toBe(! $ignored)
        ->and($tracked > 0)->toBe(! $ignored);

    $ignored
        ? Event::assertNotDispatched(UserWasActiveEvent::class)
        : Event::assertDispatched(UserWasActiveEvent::class);
})->with([
    'ignored request' => [true],
    'counted request' => [false],
]);

it('asks the callback for every request, guests included, after the route has run', function () {
    $seen = [];
    LastSeen::ignoreRequestsUsing(function (Request $request) use (&$seen): bool {
        $seen[] = $request->route()?->uri();

        return false;
    });

    $this->get('/last-seen')->assertOk();
    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/notifications/poll')->assertOk();

    expect($seen)->toBe(['last-seen', 'notifications/poll']);
});

it('replaces the callback on a second call and removes it with null', function () {
    $first = TimestampedUser::create(['email' => fake()->email()]);
    $second = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::ignoreRequestsUsing(fn (): bool => true);
    LastSeen::ignoreRequestsUsing(fn (): bool => false);

    $this->actingAs($first)->get('/last-seen')->assertOk();

    LastSeen::ignoreRequestsUsing(fn (): bool => true);
    LastSeen::ignoreRequestsUsing(null);

    $this->actingAs($second)->get('/last-seen')->assertOk();

    expect($first->fresh()->last_seen_at)->not->toBeNull()
        ->and($second->fresh()->last_seen_at)->not->toBeNull();
});

it('asks the callback once when the middleware runs globally and on the route', function (bool $ignored) {
    $calls = 0;
    LastSeen::ignoreRequestsUsing(function () use (&$calls, $ignored): bool {
        $calls++;

        return $ignored;
    });
    app(Kernel::class)->pushMiddleware(UpdateLastSeenMiddleware::class);
    Event::fake([UserWasActiveEvent::class]);

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    expect($calls)->toBe(1);
})->with([
    'a request that is ignored' => [true],
    'a request that counts' => [false],
]);

it('treats any truthy value of the callback as a request to ignore', function (mixed $returned, bool $ignored) {
    LastSeen::ignoreRequestsUsing(fn () => $returned);

    expect(LastSeen::shouldIgnoreRequest(Request::create('/last-seen')))->toBe($ignored);
})->with([
    'true' => [true, true],
    'one' => [1, true],
    'a string' => ['yes', true],
    'false' => [false, false],
    'zero' => [0, false],
    'null' => [null, false],
]);

it('never asks the callback while the package is disabled', function () {
    config(['last-seen.enabled' => false]);
    $calls = 0;
    LastSeen::ignoreRequestsUsing(function () use (&$calls): bool {
        $calls++;

        return false;
    });

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    expect($calls)->toBe(0);
});

it('reports a failing callback and keeps the response without recording anything', function () {
    Exceptions::fake();
    LastSeen::ignoreRequestsUsing(fn (): bool => throw new RuntimeException('callback failed'));
    $user = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($user)->get('/last-seen')->assertOk()->assertSee('ok');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'callback failed');
    expect($user->fresh()->last_seen_at)->toBeNull();
});

it('only applies to the middleware, not to an activity event dispatched by hand', function () {
    LastSeen::ignoreRequestsUsing(fn (): bool => true);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    Event::dispatch(new UserWasActiveEvent($user));

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('can leave out requests that were made with an API token', function () {
    LastSeen::ignoreRequestsUsing(fn (Request $request): bool => $request->bearerToken() !== null);
    $integration = TimestampedUser::create(['email' => fake()->email()]);
    $person = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($integration)->get('/last-seen', ['Authorization' => 'Bearer token'])->assertOk();
    $this->actingAs($person)->get('/last-seen')->assertOk();

    expect($integration->fresh()->last_seen_at)->toBeNull()
        ->and($person->fresh()->last_seen_at)->not->toBeNull();
});

it('counts a route that only carries the middleware', function () {
    Route::post('/activity', fn () => response()->noContent())->middleware(UpdateLastSeenMiddleware::class);
    LastSeen::ignoreRequestsUsing(fn (Request $request): bool => $request->is('notifications/*'));
    $user = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($user)->get('/notifications/poll')->assertOk();
    expect($user->fresh()->last_seen_at)->toBeNull();

    $this->actingAs($user)->post('/activity')->assertNoContent();
    expect($user->fresh()->last_seen_at)->not->toBeNull();
});
