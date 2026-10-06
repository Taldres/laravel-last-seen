<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware;
use Taldres\LastSeen\Tests\TestModels\InheritedTrackedUser;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;
use Taldres\LastSeen\Tests\TestModels\UntrackedUser;

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

it('does not dispatch the event for users that should not be tracked', function () {
    Event::fake([UserWasActiveEvent::class]);
    LastSeen::trackUsing(fn () => false);

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('does not resolve the user when the package is disabled', function () {
    $calls = 0;
    Auth::viaRequest('counting', function () use (&$calls) {
        $calls++;

        return null;
    });
    config([
        'auth.guards.counting' => ['driver' => 'counting'],
        'auth.defaults.guard' => 'counting',
        'last-seen.enabled' => false,
    ]);

    $this->get('/last-seen')->assertOk();

    expect($calls)->toBe(0);
});

it('does not dispatch the event for users that do not exist in the database', function () {
    Event::fake([UserWasActiveEvent::class]);

    Route::delete('/account', function () {
        Auth::user()?->delete();

        return response()->noContent();
    })->middleware(UpdateLastSeenMiddleware::class);

    $this->actingAs(new TimestampedUser(['email' => fake()->email()]))->get('/last-seen')->assertOk();
    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->delete('/account')->assertNoContent();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('dispatches the event once when the middleware runs globally and on the route', function () {
    Event::fake([UserWasActiveEvent::class]);
    app(Kernel::class)->pushMiddleware(UpdateLastSeenMiddleware::class);

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    Event::assertDispatchedTimes(UserWasActiveEvent::class, 1);
});

it('reports a failing write and keeps the response', function () {
    Exceptions::fake();
    $user = TimestampedUser::create(['email' => fake()->email()]);
    Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_seen_at'));

    Route::post('/posts', fn () => response('created', 201))->middleware(UpdateLastSeenMiddleware::class);

    $this->actingAs($user)->post('/posts')->assertCreated()->assertSee('created');

    Exceptions::assertReported(QueryException::class);
});

it('reports a failing event listener and keeps the response', function () {
    Exceptions::fake();
    Event::listen(UserWasActiveEvent::class, fn () => throw new RuntimeException('listener failed'));

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    Exceptions::assertReported(RuntimeException::class);
});

it('records activity when the enabled key is missing from the config', function () {
    config(['last-seen' => Arr::except(config('last-seen'), 'enabled')]);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($user)->get('/last-seen')->assertOk();

    expect(config()->has('last-seen.enabled'))->toBeFalse()
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});

it('does not record a user who logs out during the request', function () {
    Event::fake([UserWasActiveEvent::class]);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    Route::get('/logout', function () {
        Auth::logout();

        return response()->noContent();
    })->middleware(['web', UpdateLastSeenMiddleware::class]);

    $this->actingAs(TimestampedUser::create(['email' => fake()->email()]))->get('/logout')->assertNoContent();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('records a user who logs in during the request', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    Route::get('/login', function () use ($user) {
        Auth::login($user);

        return response()->noContent();
    })->middleware(['web', UpdateLastSeenMiddleware::class]);

    $this->get('/login')->assertNoContent();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('records only the user who is authenticated at the end of the request', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $admin = TimestampedUser::create(['email' => fake()->email()]);
    $impersonated = TimestampedUser::create(['email' => fake()->email()]);

    Route::get('/impersonate', function () use ($impersonated) {
        Auth::login($impersonated);

        return response()->noContent();
    })->middleware(['web', UpdateLastSeenMiddleware::class]);

    $this->actingAs($admin)->get('/impersonate')->assertNoContent();

    expect($impersonated->fresh()->last_seen_at)->not->toBeNull()
        ->and($admin->fresh()->last_seen_at)->toBeNull();
});

it('records error and redirect responses as activity and keeps their status', function (string $outcome, int $status) {
    Exceptions::fake();
    $user = TimestampedUser::create(['email' => fake()->email()]);

    Route::get('/outcome', fn () => match ($outcome) {
        'forbidden' => abort(403),
        'exception' => throw new RuntimeException('failed'),
        'validation' => throw ValidationException::withMessages(['email' => 'invalid']),
        'redirect' => redirect('/elsewhere'),
    })->middleware(UpdateLastSeenMiddleware::class);

    $this->actingAs($user)->getJson('/outcome')->assertStatus($status);

    expect($user->fresh()->last_seen_at)->not->toBeNull();
})->with([
    'forbidden' => ['forbidden', 403],
    'server error' => ['exception', 500],
    'validation error' => ['validation', 422],
    'redirect' => ['redirect', 302],
]);

it('lets exceptions propagate when exception handling is disabled', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);

    Route::get('/failing', fn () => throw new RuntimeException('failed'))->middleware(UpdateLastSeenMiddleware::class);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->get('/failing'))->toThrow(RuntimeException::class, 'failed')
        ->and($user->fresh()->last_seen_at)->toBeNull();
});

it('returns the response it received unchanged', function (Response $response) {
    $user = TimestampedUser::create(['email' => fake()->email()]);
    $this->actingAs($user);

    $handled = app(UpdateLastSeenMiddleware::class)->handle(Request::create('/'), fn () => $response);

    expect($handled)->toBe($response)
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
})->with([
    'plain' => fn () => new Response('ok'),
    'json' => fn () => new JsonResponse(['ok' => true]),
    'streamed' => fn () => new StreamedResponse(fn () => print ('ok')),
    'redirect' => fn () => new RedirectResponse('/elsewhere'),
]);

it('ignores Eloquent users without the LastSeen trait', function () {
    Event::fake([UserWasActiveEvent::class]);

    $this->actingAs(UntrackedUser::create(['email' => fake()->email()]))->get('/last-seen')->assertOk();

    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('records users that get the LastSeen trait from a parent class', function () {
    $user = InheritedTrackedUser::create(['email' => fake()->email()]);

    $this->actingAs($user)->get('/last-seen')->assertOk();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('writes last_seen_at again across requests only after the update threshold', function () {
    $this->freezeSecond();
    $user = TimestampedUser::create(['email' => fake()->email()]);

    $this->actingAs($user)->get('/last-seen');
    $first = $user->fresh()->last_seen_at;

    $this->travel(30)->seconds();
    $this->actingAs($user->fresh())->get('/last-seen');

    expect($user->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    $this->actingAs($user->fresh())->get('/last-seen');

    expect($user->fresh()->last_seen_at->equalTo(now()))->toBeTrue();
});

it('calls the trackUsing callback once per request within the update threshold', function () {
    $calls = 0;
    LastSeen::trackUsing(function () use (&$calls) {
        $calls++;

        return true;
    });

    $user = TimestampedUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    $this->actingAs($user)->get('/last-seen')->assertOk();

    expect($calls)->toBe(1);
});

describe('custom guards', function () {
    beforeEach(function () {
        $this->defaultGuard = config('auth.defaults.guard');

        Auth::viaRequest('last-seen-header', fn (Request $request) => TimestampedUser::find($request->header('X-User-Id')));
        config(['auth.guards.last-seen-header' => ['driver' => 'last-seen-header']]);
    });

    afterEach(function () {
        Auth::forgetGuards();
        config(['auth.defaults.guard' => $this->defaultGuard]);
    });

    it('updates last_seen_at when the authentication middleware runs before it', function () {
        Route::get('/header-auth', fn () => 'ok')->middleware(['auth:last-seen-header', UpdateLastSeenMiddleware::class]);
        $user = TimestampedUser::create(['email' => fake()->email()]);

        $this->get('/header-auth', ['X-User-Id' => (string) $user->id])->assertOk();

        expect($user->fresh()->last_seen_at)->not->toBeNull();
    });

    it('ignores a user resolved on a non-default guard without an auth middleware', function () {
        Route::get('/header-inline', fn () => (string) Auth::guard('last-seen-header')->id())->middleware(UpdateLastSeenMiddleware::class);
        $user = TimestampedUser::create(['email' => fake()->email()]);

        $this->get('/header-inline', ['X-User-Id' => (string) $user->id])->assertOk()->assertSee((string) $user->id);

        expect($user->fresh()->last_seen_at)->toBeNull();
    });
});

describe('session authentication', function () {
    beforeEach(function () {
        $kernel = app(Kernel::class);
        $this->globalMiddleware = $kernel->getGlobalMiddleware();
        $this->middlewareGroups = $kernel->getMiddlewareGroups();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'auth.providers.users.model' => TimestampedUser::class,
        ]);
    });

    afterEach(function () {
        app(Kernel::class)
            ->setGlobalMiddleware($this->globalMiddleware)
            ->setMiddlewareGroups($this->middlewareGroups);
    });

    it('records the session user when the middleware runs globally outside the web group', function () {
        app(Kernel::class)->pushMiddleware(UpdateLastSeenMiddleware::class);
        Route::get('/dashboard', fn () => 'ok')->middleware('web');
        $user = TimestampedUser::create(['email' => fake()->email()]);

        $this->withSession([Auth::guard('web')->getName() => $user->id])->get('/dashboard')->assertOk();

        expect($user->fresh()->last_seen_at)->not->toBeNull();
    });

    it('records the session user when the middleware runs before the session is started', function () {
        app(Kernel::class)->prependMiddlewareToGroup('web', UpdateLastSeenMiddleware::class);
        Route::get('/dashboard', fn () => 'ok')->middleware('web');
        $user = TimestampedUser::create(['email' => fake()->email()]);

        $this->withSession([Auth::guard('web')->getName() => $user->id])->get('/dashboard')->assertOk();

        expect($user->fresh()->last_seen_at)->not->toBeNull();
    });
});
