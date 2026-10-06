<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Tests\TestModels\CastsMethodUser;
use Taldres\LastSeen\Tests\TestModels\ImmutableCastUser;
use Taldres\LastSeen\Tests\TestModels\SubclassedUser;
use Taldres\LastSeen\Tests\TestModels\TraitComposedUser;
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

it('does not record a fraction of a second before the update threshold', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:59.999999'));

    $loaded = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:00:00'])->fresh();
    $partial = User::query()->select(['id', 'email'])->findOrFail(
        User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:00:00'])->id,
    );

    expect(LastSeen::record($loaded))->toBeFalse()
        ->and(LastSeen::record($partial))->toBeFalse();
});

it('keeps the recorded last_seen_at in memory exactly as it was stored', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00.900000'));

    $user = User::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and($user->last_seen_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-06 12:00:00.000000')
        ->and($user->getOriginal('last_seen_at')->equalTo($user->fresh()->last_seen_at))->toBeTrue()
        ->and($user->isDirty())->toBeFalse();
});

it('records on every call with an update threshold of 0', function () {
    config(['last-seen.update_threshold' => 0]);
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $user = User::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-10-06 12:00:01'));

    expect(LastSeen::record($user))->toBeTrue()
        ->and($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 12:00:01');
});

it('cuts recentlySeenSince() to whole seconds', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:05:00.750000'));

    expect(LastSeen::recentlySeenSince()->format('Y-m-d H:i:s.u'))->toBe('2026-10-06 12:00:00.000000');
});

it('leaves the model unchanged when another instance recorded in the meantime', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:00:00']);
    $first = User::findOrFail($user->id);
    $second = User::findOrFail($user->id);

    LastSeen::record($first);
    $this->travel(5)->seconds();

    expect(LastSeen::record($second))->toBeFalse()
        ->and($second->last_seen_at->toDateTimeString())->toBe('2026-10-06 11:00:00')
        ->and($second->isDirty())->toBeFalse()
        ->and($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 12:00:00');
});

it('fires no model events when recording or forgetting', function () {
    $user = User::create(['email' => fake()->email()]);

    $fired = [];
    foreach (['saving', 'saved', 'updating', 'updated'] as $event) {
        User::{$event}(function () use (&$fired, $event) {
            $fired[] = $event;
        });
    }

    expect(LastSeen::record($user))->toBeTrue();

    LastSeen::forget($user);

    expect($fired)->toBe([]);
});

it('does nothing for an unsaved model, without queries and without asking the trackUsing callback', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $called = false;
    LastSeen::trackUsing(function () use (&$called) {
        $called = true;

        return true;
    });

    $user = (new User)->forceFill(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:00:00']);

    DB::enableQueryLog();

    expect(LastSeen::record($user))->toBeFalse();

    LastSeen::forget($user);

    expect($called)->toBeFalse()
        ->and(DB::getQueryLog())->toBe([])
        ->and($user->last_seen_at->toDateTimeString())->toBe('2026-10-06 11:00:00');
});

it('does not record a row that was deleted after the model was loaded', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:00:00'])->fresh();
    User::query()->whereKey($user->id)->delete();

    expect(LastSeen::record($user))->toBeFalse()
        ->and($user->last_seen_at->toDateTimeString())->toBe('2026-10-06 11:00:00')
        ->and($user->isDirty())->toBeFalse();
});

it('does not record a model loaded without its key and leaves all rows alone', function () {
    $other = User::create(['email' => fake()->email()]);
    $user = User::create(['email' => $email = fake()->email()]);
    $keyless = User::query()->select(['email', 'last_seen_at'])->where('email', $email)->firstOrFail();

    expect(LastSeen::record($keyless))->toBeFalse()
        ->and($user->fresh()->last_seen_at)->toBeNull()
        ->and($other->fresh()->last_seen_at)->toBeNull();
});

it('records again right after forgetting, even within the update threshold', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()])->fresh();

    expect(LastSeen::record($user))->toBeFalse();

    LastSeen::forget($user);

    expect($user->getOriginal('last_seen_at'))->toBeNull()
        ->and(LastSeen::record($user))->toBeTrue()
        ->and($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 12:00:00');
});

it('does not ask the trackUsing callback while the package is disabled', function () {
    config(['last-seen.enabled' => false]);

    $called = false;
    LastSeen::trackUsing(function () use (&$called) {
        $called = true;

        return true;
    });

    $user = User::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeFalse()
        ->and(LastSeen::shouldTrack($user))->toBeFalse()
        ->and($called)->toBeFalse();
});

it('falls back to the default thresholds when the config keys are missing', function () {
    config(['last-seen' => []]);
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $update = LastSeenDefaultThreshold::Update->value;
    $recentlySeen = LastSeenDefaultThreshold::RecentlySeen->value;

    $within = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subSeconds($update - 1)])->fresh();
    $outside = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subSeconds($update)])->fresh();
    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subSeconds($recentlySeen)])->fresh();
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subSeconds($recentlySeen + 1)])->fresh();

    expect(LastSeen::shouldTrack($within))->toBeTrue()
        ->and(LastSeen::record($within))->toBeFalse()
        ->and(LastSeen::record($outside))->toBeTrue()
        ->and($recent->recentlySeen())->toBeTrue()
        ->and($stale->recentlySeen())->toBeFalse();
});

it('treats the package as enabled when only the enabled key is missing', function () {
    config(['last-seen' => Arr::except(config()->array('last-seen'), 'enabled')]);

    $user = User::create(['email' => fake()->email()]);

    expect(config()->has('last-seen.enabled'))->toBeFalse()
        ->and(LastSeen::shouldTrack($user))->toBeTrue()
        ->and(LastSeen::record($user))->toBeTrue();
});

it('throws for an update threshold that is not an integer', function (mixed $value) {
    config(['last-seen.update_threshold' => $value]);

    $user = User::create(['email' => fake()->email()]);

    expect(fn () => LastSeen::record($user))
        ->toThrow(InvalidArgumentException::class, 'last-seen.update_threshold');
})->with([
    'numeric string' => '60',
    'float' => 60.0,
    'null' => null,
    'boolean' => true,
]);

it('throws for a recently seen threshold that is not an integer', function (mixed $value) {
    config(['last-seen.recently_seen_threshold' => $value]);

    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    expect(fn () => LastSeen::recentlySeen($user))
        ->toThrow(InvalidArgumentException::class, 'last-seen.recently_seen_threshold')
        ->and(fn () => User::onlyRecentlySeen()->get())
        ->toThrow(InvalidArgumentException::class, 'last-seen.recently_seen_threshold');
})->with([
    'numeric string' => '300',
    'null' => null,
]);

it('passes the recorded model instance itself to the trackUsing callback', function () {
    $received = null;
    LastSeen::trackUsing(function (Model $user) use (&$received) {
        $received = $user;

        return true;
    });

    $user = User::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and($received)->toBe($user);
});

it('replaces the trackUsing callback on a second call', function () {
    LastSeen::trackUsing(fn (): bool => false);
    LastSeen::trackUsing(fn (): bool => true);

    expect(LastSeen::record(User::create(['email' => fake()->email()])))->toBeTrue();
});

it('removes the trackUsing callback when null is passed', function () {
    LastSeen::trackUsing(fn (): bool => false);
    LastSeen::trackUsing(null);

    expect(LastSeen::record(User::create(['email' => fake()->email()])))->toBeTrue();
});

it('lets exceptions from the trackUsing callback through without writing', function () {
    LastSeen::trackUsing(fn (): bool => throw new RuntimeException('Callback failed.'));

    $user = User::create(['email' => fake()->email()]);

    expect(fn () => LastSeen::record($user))->toThrow(RuntimeException::class, 'Callback failed.')
        ->and($user->fresh()->last_seen_at)->toBeNull();
});

it('does not ask the trackUsing callback about models without the trait', function () {
    $called = false;
    LastSeen::trackUsing(function () use (&$called) {
        $called = true;

        return true;
    });

    $model = new class extends Model
    {
        protected $table = 'users';
    };

    expect(LastSeen::shouldTrack($model))->toBeFalse()
        ->and($called)->toBeFalse();
});

it('tracks subclasses and models that use the trait through another trait', function (string $model) {
    $user = $model::create(['email' => fake()->email()]);

    expect(LastSeen::shouldTrack($user))->toBeTrue()
        ->and($user->updateLastSeenAt())->toBeTrue()
        ->and($user->recentlySeen())->toBeTrue();
})->with([
    'subclass' => SubclassedUser::class,
    'trait through another trait' => TraitComposedUser::class,
]);

it('records and checks activity with an immutable last_seen_at cast', function (string $model) {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $user = $model::create(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and($user->last_seen_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(LastSeen::record($user))->toBeFalse()
        ->and(LastSeen::recentlySeen($user))->toBeTrue()
        ->and($model::onlyRecentlySeen()->pluck('id')->all())->toBe([$user->id]);

    $this->travel(60)->seconds();

    expect(LastSeen::record($user))->toBeTrue()
        ->and($user->last_seen_at->toDateTimeString())->toBe('2026-10-06 12:01:00');
})->with([
    'cast in the $casts property' => ImmutableCastUser::class,
    'cast in the casts() method' => CastsMethodUser::class,
]);

it('records and checks activity when Date uses CarbonImmutable', function () {
    Date::use(CarbonImmutable::class);

    try {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00.400000'));

        $user = User::create(['email' => fake()->email()]);

        expect(LastSeen::recentlySeenSince()->format('Y-m-d H:i:s.u'))->toBe('2026-10-06 11:55:00.000000')
            ->and(LastSeen::record($user))->toBeTrue()
            ->and($user->last_seen_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and(LastSeen::record($user))->toBeFalse()
            ->and($user->recentlySeen())->toBeTrue()
            ->and(User::onlyRecentlySeen()->pluck('id')->all())->toBe([$user->id]);

        $this->travel(60)->seconds();

        expect(LastSeen::record($user))->toBeTrue()
            ->and($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 12:01:00');
    } finally {
        Date::useDefault();
    }
});
