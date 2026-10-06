<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\LastSeenManager;
use Taldres\LastSeen\LastSeenServiceProvider;
use Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;

it('resolves the same manager through the facade and the container', function () {
    expect(LastSeen::getFacadeRoot())->toBe(app(LastSeenManager::class));
});

it('records activity only once within the update threshold', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and(LastSeen::record($user))->toBeFalse()
        ->and(LastSeen::recentlySeen($user))->toBeTrue()
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});

it('passes the user to the trackUsing callback', function () {
    $tracked = TimestampedUser::create(['email' => fake()->email()]);
    $untracked = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::trackUsing(fn (Model $user): bool => $user->is($tracked));

    expect(LastSeen::shouldTrack($tracked))->toBeTrue()
        ->and(LastSeen::shouldTrack($untracked))->toBeFalse()
        ->and(LastSeen::record($untracked))->toBeFalse()
        ->and($untracked->fresh()->last_seen_at)->toBeNull();
});

it('does not track when the feature is disabled', function () {
    config(['last-seen.enabled' => false]);

    expect(LastSeen::shouldTrack(TimestampedUser::create(['email' => fake()->email()])))->toBeFalse();
});

it('does not track models without the LastSeen trait', function () {
    $model = new class extends Model
    {
        protected $table = 'users';
    };

    expect(LastSeen::shouldTrack($model))->toBeFalse();
});

it('forgets the stored timestamp', function () {
    $user = TimestampedUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    LastSeen::forget($user);

    expect($user->last_seen_at)->toBeNull()
        ->and($user->fresh()->last_seen_at)->toBeNull()
        ->and(LastSeen::recentlySeen($user))->toBeFalse();
});

it('lets the middleware use a mocked facade', function () {
    Route::get('/mocked', fn () => 'ok')->middleware(UpdateLastSeenMiddleware::class);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::shouldReceive('shouldTrack')->once()->with($user)->andReturn(true);
    LastSeen::shouldReceive('record')->once()->with($user)->andReturn(true);

    $this->actingAs($user)->get('/mocked')->assertOk();

    expect($user->fresh()->last_seen_at)->toBeNull();
});

it('uses a swapped manager in the middleware, the listener and the trait', function () {
    Route::get('/swapped', fn () => 'ok')->middleware(UpdateLastSeenMiddleware::class);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    $manager = new class extends LastSeenManager
    {
        public int $records = 0;

        public function record(Model $user): bool
        {
            $this->records++;

            return false;
        }
    };

    LastSeen::swap($manager);

    $this->actingAs($user)->get('/swapped')->assertOk();
    $user->updateLastSeenAt();

    expect($manager->records)->toBe(2)
        ->and($user->fresh()->last_seen_at)->toBeNull();
});

it('documents every public manager method on the facade', function () {
    $docComment = (string) (new ReflectionClass(LastSeen::class))->getDocComment();

    $methods = collect((new ReflectionClass(LastSeenManager::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isConstructor() || $method->isStatic())
        ->map(fn (ReflectionMethod $method) => $method->getName());

    expect($methods)->not->toBeEmpty()
        ->each(fn ($method) => expect($docComment)->toMatch('/@method\s+static\s+\S+\s+'.$method->value.'\(/'));
});

it('declares the facade alias and the service provider for package discovery', function () {
    $composer = json_decode(File::get(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'composer.json'), true);

    expect($composer['extra']['laravel'])->toBe([
        'providers' => [LastSeenServiceProvider::class],
        'aliases' => ['LastSeen' => LastSeen::class],
    ]);
});
