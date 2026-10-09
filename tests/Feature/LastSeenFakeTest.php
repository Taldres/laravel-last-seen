<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;
use ReflectionMethod;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\LastSeenManager;
use Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware;
use Taldres\LastSeen\Testing\LastSeenFake;
use Taldres\LastSeen\Tests\TestModels\TimestampedUser;
use Taldres\LastSeen\Tests\TestModels\UntrackedUser;

it('swaps the manager for a fake in the facade and the container', function () {
    $fake = LastSeen::fake();

    expect($fake)->toBeInstanceOf(LastSeenFake::class)
        ->and(LastSeen::getFacadeRoot())->toBe($fake)
        ->and(app(LastSeenManager::class))->toBe($fake)
        ->and(LastSeen::isFake())->toBeTrue();
});

it('records activity from the middleware without writing it', function () {
    Route::get('/faked', fn () => 'ok')->middleware(UpdateLastSeenMiddleware::class);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::fake();

    $this->actingAs($user)->get('/faked')->assertOk();

    LastSeen::assertRecorded($user);
    LastSeen::assertRecordedTimes($user);

    expect($user->last_seen_at)->toBeNull()
        ->and($user->fresh()->last_seen_at)->toBeNull();
});

it('records activity from the trait and ignores the update threshold', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);
    $other = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::fake();

    expect($user->updateLastSeenAt())->toBeTrue()
        ->and(LastSeen::record($user))->toBeTrue()
        ->and($user->fresh()->last_seen_at)->toBeNull();

    LastSeen::assertRecordedTimes($user, 2);
    LastSeen::assertRecorded(fn (Model $recorded): bool => $recorded->is($user));
    LastSeen::assertNotRecorded($other);
    LastSeen::assertNothingForgotten();

    expect(LastSeen::recorded())->toHaveCount(2)
        ->and(LastSeen::recorded($other))->toBeEmpty();
});

it('keeps the trackUsing callback registered before faking', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::trackUsing(fn (): bool => false);
    LastSeen::fake();

    expect(LastSeen::record($user))->toBeFalse()
        ->and(LastSeen::shouldTrack($user))->toBeFalse();

    LastSeen::assertNothingRecorded();
});

it('applies a trackUsing callback registered on the fake', function () {
    $tracked = TimestampedUser::create(['email' => fake()->email()]);
    $untracked = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::fake();
    LastSeen::trackUsing(fn (Model $user): bool => $user->is($tracked));

    LastSeen::record($tracked);
    LastSeen::record($untracked);

    LastSeen::assertRecorded($tracked);
    LastSeen::assertNotRecorded($untracked);
});

it('records nothing when the package is disabled or the model is not tracked', function () {
    config(['last-seen.enabled' => false]);
    $user = TimestampedUser::create(['email' => fake()->email()]);

    LastSeen::fake();

    expect(LastSeen::record($user))->toBeFalse()
        ->and(LastSeen::record(UntrackedUser::create(['email' => fake()->email()])))->toBeFalse()
        ->and(LastSeen::record(new TimestampedUser))->toBeFalse();

    LastSeen::assertNothingRecorded();
});

it('records forgotten users without writing', function () {
    $user = TimestampedUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    LastSeen::fake();

    $user->forgetLastSeenAt();
    LastSeen::forget(new TimestampedUser);

    LastSeen::assertForgotten($user);
    LastSeen::assertNothingRecorded();

    expect(LastSeen::forgotten())->toHaveCount(1)
        ->and($user->last_seen_at)->not->toBeNull()
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});

it('asks the real manager whether a user was recently seen', function () {
    $user = TimestampedUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    LastSeen::fake();

    expect(LastSeen::recentlySeen($user))->toBeTrue()
        ->and(TimestampedUser::onlyRecentlySeen()->pluck('id')->all())->toBe([$user->id]);
});

it('wraps the real manager when faking twice', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);
    $manager = app(LastSeenManager::class);

    LastSeen::fake()->record($user);
    $fake = LastSeen::fake();

    expect((fn () => $this->manager)->call($fake))->toBe($manager);

    LastSeen::assertNothingRecorded();
});

it('fails with a message naming the user', function () {
    $user = TimestampedUser::create(['email' => fake()->email()]);
    $name = TimestampedUser::class.':'.$user->id;

    $fake = LastSeen::fake();

    expect(fn () => $fake->assertRecorded($user))
        ->toThrow(ExpectationFailedException::class, "The expected user [{$name}] was not recorded.")
        ->and(fn () => $fake->assertForgotten(fn (): bool => true))
        ->toThrow(ExpectationFailedException::class, 'The expected user matching the given callback was not forgotten.');

    $fake->record($user);
    $fake->forget($user);

    expect(fn () => $fake->assertRecordedTimes($user, 2))
        ->toThrow(ExpectationFailedException::class, "The expected user [{$name}] was recorded 1 times instead of 2 times.")
        ->and(fn () => $fake->assertNotRecorded($user))
        ->toThrow(ExpectationFailedException::class, "The unexpected user [{$name}] was recorded.")
        ->and(fn () => $fake->assertNothingRecorded())
        ->toThrow(ExpectationFailedException::class, '1 unexpected users were recorded.')
        ->and(fn () => $fake->assertNotForgotten($user))
        ->toThrow(ExpectationFailedException::class, "The unexpected user [{$name}] was forgotten.")
        ->and(fn () => $fake->assertNothingForgotten())
        ->toThrow(ExpectationFailedException::class, '1 unexpected users were forgotten.');
});

it('documents every public fake method on the facade', function () {
    $docComment = (string) (new ReflectionClass(LastSeen::class))->getDocComment();

    $methods = collect((new ReflectionClass(LastSeenFake::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isConstructor() || $method->isStatic())
        ->map(fn (ReflectionMethod $method) => $method->getName());

    expect($methods)->not->toBeEmpty()
        ->each(fn ($method) => expect($docComment)->toMatch('/@method\s+static\s+\S+\s+'.$method->value.'\(/'));
});
