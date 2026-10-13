<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RuntimeException;
use Taldres\LastSeen\Events\LastSeenRecordedEvent;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware;
use Taldres\LastSeen\Tests\TestModels\FormattedLastSeenUser;
use Taldres\LastSeen\Tests\TestModels\PlainDateUser;
use Taldres\LastSeen\Tests\TestModels\QueuedRecordedListener;
use Taldres\LastSeen\Tests\TestModels\User;
use Taldres\LastSeen\Tests\TestModels\UtcStoredUser;

beforeEach(function () {
    Route::get('/last-seen', fn () => 'ok')->middleware(UpdateLastSeenMiddleware::class);
});

it('fires the event once per write, whichever way the timestamp is recorded', function () {
    Event::fake([LastSeenRecordedEvent::class]);

    $viaMiddleware = User::create(['email' => fake()->email()]);
    $viaTrait = User::create(['email' => fake()->email()]);
    $viaFacade = User::create(['email' => fake()->email()]);

    $this->actingAs($viaMiddleware)->get('/last-seen')->assertOk();
    $viaTrait->updateLastSeenAt();
    LastSeen::record($viaFacade);

    Event::assertDispatchedTimes(LastSeenRecordedEvent::class, 3);

    foreach ([$viaMiddleware, $viaTrait, $viaFacade] as $user) {
        Event::assertDispatched(LastSeenRecordedEvent::class, fn (LastSeenRecordedEvent $event) => $event->user->is($user));
        expect($user->fresh()->last_seen_at)->not->toBeNull();
    }
});

it('fires no event when nothing was written', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    Event::fake([LastSeenRecordedEvent::class]);

    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()])->fresh();
    expect(LastSeen::record($recent))->toBeFalse();

    config(['last-seen.enabled' => false]);
    expect(LastSeen::record(User::create(['email' => fake()->email()])))->toBeFalse();
    config(['last-seen.enabled' => true]);

    LastSeen::trackUsing(fn (): bool => false);
    expect(LastSeen::record(User::create(['email' => fake()->email()])))->toBeFalse();
    LastSeen::trackUsing(null);

    expect(LastSeen::record(new User(['email' => fake()->email()])))->toBeFalse();

    $untracked = new class extends Model
    {
        protected $table = 'users';

        protected $guarded = [];

        public $timestamps = false;
    };
    $untracked->forceFill(['email' => fake()->email()])->save();
    expect(LastSeen::record($untracked))->toBeFalse();

    $deleted = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:00:00'])->fresh();
    User::query()->whereKey($deleted->id)->delete();
    expect(LastSeen::record($deleted))->toBeFalse();

    $email = fake()->email();
    User::create(['email' => $email]);
    expect(LastSeen::record(User::query()->select(['email', 'last_seen_at'])->where('email', $email)->firstOrFail()))->toBeFalse();

    Event::assertNotDispatched(LastSeenRecordedEvent::class);
});

it('fires the event only for the request that wins the race', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    Event::fake([LastSeenRecordedEvent::class]);

    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:00:00']);
    $first = User::findOrFail($user->id);
    $second = User::findOrFail($user->id);

    expect(LastSeen::record($first))->toBeTrue()
        ->and(LastSeen::record($second))->toBeFalse();

    Event::assertDispatchedTimes(LastSeenRecordedEvent::class, 1);
});

it('reports the previous value and whether the user became recently seen', function (?string $previous, bool $becameRecentlySeen) {
    $this->travelTo(Carbon::parse('2026-10-06 12:10:00'));
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => $previous])->fresh();

    expect(LastSeen::record($user))->toBeTrue()
        ->and($recorded)->toBeInstanceOf(LastSeenRecordedEvent::class)
        ->and($recorded->previousLastSeenAt?->format('Y-m-d H:i:s'))->toBe($previous)
        ->and($recorded->becameRecentlySeen)->toBe($becameRecentlySeen);
})->with([
    'never seen' => [null, true],
    'older than the recently seen threshold' => ['2026-10-06 12:00:00', true],
    'one second before the cutoff' => ['2026-10-06 12:04:59', true],
    'exactly at the cutoff' => ['2026-10-06 12:05:00', false],
    'within the recently seen threshold' => ['2026-10-06 12:08:00', false],
    'in the future' => ['2026-10-06 12:30:00', false],
]);

it('treats a model that was just created as never seen', function () {
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    LastSeen::record(User::create(['email' => fake()->email()]));

    expect($recorded->previousLastSeenAt)->toBeNull()
        ->and($recorded->becameRecentlySeen)->toBeTrue();
});

it('reports an unknown previous value for a model loaded without last_seen_at', function (bool $strict) {
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    $user = User::create(['email' => fake()->email()]);
    $partial = User::query()->select(['id', 'email'])->findOrFail($user->id);

    Model::preventAccessingMissingAttributes($strict);

    try {
        expect(LastSeen::record($partial))->toBeTrue()
            ->and($recorded->user)->toBe($partial)
            ->and($recorded->previousLastSeenAt)->toBeNull()
            ->and($recorded->becameRecentlySeen)->toBeFalse()
            ->and($user->fresh()->last_seen_at)->not->toBeNull();
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
})->with([
    'strict mode' => [true],
    'lenient mode' => [false],
]);

it('reports an unknown previous value when the cast does not return a Carbon instance', function () {
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    $user = PlainDateUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => Carbon::parse('2026-10-06 11:00:00')])->fresh();

    expect(LastSeen::record($user))->toBeTrue()
        ->and($recorded->previousLastSeenAt)->toBeNull()
        ->and($recorded->becameRecentlySeen)->toBeFalse();
});

it('marks every write as becoming recently seen when the recently seen threshold is below the update threshold', function () {
    config(['last-seen.update_threshold' => 60, 'last-seen.recently_seen_threshold' => 30]);
    $becameRecentlySeen = [];
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$becameRecentlySeen) {
        $becameRecentlySeen[] = $event->becameRecentlySeen;
    });

    $user = User::create(['email' => fake()->email()]);

    LastSeen::record($user);
    $this->travel(61)->seconds();
    LastSeen::record($user);

    expect($becameRecentlySeen)->toBe([true, true]);
});

it('reports a written value that equals the stored timestamp', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00.750000'));
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    $user = User::create(['email' => fake()->email()]);
    LastSeen::record($user);

    // SQL Server keeps milliseconds in the date format, the other databases whole seconds.
    $stored = Carbon::createFromFormat($user->getDateFormat(), now()->format($user->getDateFormat()));

    expect($recorded->recordedAt->format('Y-m-d H:i:s.u'))->toBe($stored?->format('Y-m-d H:i:s.u'))
        ->and($recorded->recordedAt->eq($user->last_seen_at))->toBeTrue()
        ->and($recorded->recordedAt->eq($user->fresh()->last_seen_at))->toBeTrue();
});

it('reports the stored value as the written value for a custom cast', function (string $model) {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00.750000'));
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    $user = $model::forceCreate(['email' => fake()->email(), 'last_seen_at' => Carbon::parse('2026-10-06 11:00:00')])->fresh();
    LastSeen::record($user);

    expect($recorded->recordedAt->format('Y-m-d H:i:s.u'))->toBe('2026-10-06 12:00:00.000000')
        ->and($recorded->recordedAt->eq($user->fresh()->last_seen_at))->toBeTrue();
})->with([
    'cast returning CarbonImmutable' => [UtcStoredUser::class],
    'cast returning DateTimeImmutable' => [PlainDateUser::class],
]);

it('reports the timestamp as the written value when an accessor returns no date', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00.750000'));
    $recorded = null;
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$recorded) {
        $recorded = $event;
    });

    $user = FormattedLastSeenUser::forceCreate(['email' => fake()->email()]);
    LastSeen::record($user);

    expect($recorded->recordedAt->format('Y-m-d H:i:s.u'))->toBe('2026-10-06 12:00:00.750000');
});

it('keeps the written value when a queued listener reloads a user who was seen again', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    $events = [];
    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$events) {
        $events[] = $event;
    });

    $user = User::create(['email' => fake()->email()]);
    LastSeen::record($user);
    $serialized = serialize($events[0]);

    $this->travel(2)->minutes();
    LastSeen::record($user);

    $restored = unserialize($serialized);

    expect($restored->user->last_seen_at?->toDateTimeString())->toBe('2026-10-06 12:02:00')
        ->and($restored->recordedAt->toDateTimeString())->toBe('2026-10-06 12:00:00');
});

it('uses the current time as the written value when the event is created without one', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    $user = User::create(['email' => fake()->email()]);

    expect((new LastSeenRecordedEvent($user, null, true))->recordedAt->toDateTimeString())->toBe('2026-10-06 12:00:00')
        ->and((new LastSeenRecordedEvent($user, null, true, Carbon::parse('2026-10-06 11:00:00')))->recordedAt->toDateTimeString())
        ->toBe('2026-10-06 11:00:00');
});

it('hands the listener the model that was just recorded', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    $user = User::create(['email' => fake()->email()]);
    $seen = null;

    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$seen, $user) {
        $seen = [
            'same instance' => $event->user === $user,
            'last_seen_at' => $event->user->last_seen_at?->toDateTimeString(),
            'dirty' => $event->user->isDirty(),
        ];
    });

    LastSeen::record($user);

    expect($seen)->toBe(['same instance' => true, 'last_seen_at' => '2026-10-06 12:00:00', 'dirty' => false]);
});

it('dispatches after the transaction commits and drops the event when it rolls back', function () {
    // The DDL in the test setup commits implicitly on these databases, which ends the transaction RefreshDatabase opened.
    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped('MySQL and MariaDB commit the test transaction when the tables are created.');
    }

    $calls = 0;
    Event::listen(LastSeenRecordedEvent::class, function () use (&$calls) {
        $calls++;
    });
    $user = User::create(['email' => fake()->email()]);

    try {
        DB::transaction(function () use ($user) {
            LastSeen::record($user);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($calls)->toBe(0)
        ->and($user->fresh()->last_seen_at)->toBeNull()
        ->and(new LastSeenRecordedEvent($user, null, true))->toBeInstanceOf(ShouldDispatchAfterCommit::class);

    DB::transaction(function () use ($user, &$calls) {
        LastSeen::record($user->fresh());

        expect($calls)->toBe(0);
    });

    expect($calls)->toBe(1);
});

it('does not fire a second event when a listener records the user again', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    $user = User::create(['email' => fake()->email()]);
    $dispatched = 0;

    Event::listen(LastSeenRecordedEvent::class, function (LastSeenRecordedEvent $event) use (&$dispatched) {
        $dispatched++;
        $event->user->updateLastSeenAt();
    });

    LastSeen::record($user);

    expect($dispatched)->toBe(1);
});

it('lets an exception from a listener out of record() after the row was written', function () {
    Event::listen(LastSeenRecordedEvent::class, fn () => throw new RuntimeException('listener failed'));
    $user = User::create(['email' => fake()->email()]);

    expect(fn () => LastSeen::record($user))->toThrow(RuntimeException::class, 'listener failed')
        ->and($user->fresh()->last_seen_at)->not->toBeNull()
        ->and($user->last_seen_at)->not->toBeNull();
});

it('reports a failing listener and keeps the response of the request', function () {
    Exceptions::fake();
    Event::listen(LastSeenRecordedEvent::class, fn () => throw new RuntimeException('listener failed'));

    $this->actingAs(User::create(['email' => fake()->email()]))->get('/last-seen')->assertOk()->assertSee('ok');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'listener failed');
});

it('queues a listener only when the user became recently seen', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:10:00'));
    QueuedRecordedListener::$events = [];
    Event::listen(LastSeenRecordedEvent::class, QueuedRecordedListener::class);

    $neverSeen = User::create(['email' => fake()->email()]);
    $active = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:08:00'])->fresh();

    try {
        LastSeen::record($neverSeen);
        LastSeen::record($active);

        expect(QueuedRecordedListener::$events)->toHaveCount(1)
            ->and(QueuedRecordedListener::$events[0]->user)->not->toBe($neverSeen)
            ->and(QueuedRecordedListener::$events[0]->user->is($neverSeen))->toBeTrue()
            ->and(QueuedRecordedListener::$events[0]->user->last_seen_at?->toDateTimeString())->toBe('2026-10-06 12:10:00')
            ->and(QueuedRecordedListener::$events[0]->previousLastSeenAt)->toBeNull()
            ->and(QueuedRecordedListener::$events[0]->becameRecentlySeen)->toBeTrue();
    } finally {
        QueuedRecordedListener::$events = [];
    }
});

it('keeps the previous and the written value when the event is serialized', function () {
    $user = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:00:00']);

    $restored = unserialize(serialize(new LastSeenRecordedEvent($user, Carbon::parse('2026-10-06 11:00:00'), true, Carbon::parse('2026-10-06 12:00:00'))));

    expect($restored->user->is($user))->toBeTrue()
        ->and($restored->previousLastSeenAt?->toDateTimeString())->toBe('2026-10-06 11:00:00')
        ->and($restored->becameRecentlySeen)->toBeTrue()
        ->and($restored->recordedAt->toDateTimeString())->toBe('2026-10-06 12:00:00');
});

it('fires the event while the write still happens under Event::fake()', function () {
    Event::fake([LastSeenRecordedEvent::class]);
    $user = User::create(['email' => fake()->email()]);

    LastSeen::record($user);

    Event::assertDispatched(LastSeenRecordedEvent::class);
    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('fires no event for the fake, which writes nothing', function () {
    LastSeen::fake();
    Event::fake([LastSeenRecordedEvent::class]);
    $user = User::create(['email' => fake()->email()]);

    LastSeen::record($user);

    Event::assertNotDispatched(LastSeenRecordedEvent::class);
    LastSeen::assertRecorded($user);
});

it('fires the event but not the activity event when recording', function () {
    Event::fake([LastSeenRecordedEvent::class, UserWasActiveEvent::class]);

    LastSeen::record(User::create(['email' => fake()->email()]));

    Event::assertDispatched(LastSeenRecordedEvent::class);
    Event::assertNotDispatched(UserWasActiveEvent::class);
});

it('fails before the row is written when the recently seen threshold is invalid', function () {
    Event::fake([LastSeenRecordedEvent::class]);
    config(['last-seen.recently_seen_threshold' => -1]);
    $user = User::create(['email' => fake()->email()]);

    expect(fn () => LastSeen::record($user))->toThrow(InvalidArgumentException::class)
        ->and($user->fresh()->last_seen_at)->toBeNull();

    Event::assertNotDispatched(LastSeenRecordedEvent::class);
});
