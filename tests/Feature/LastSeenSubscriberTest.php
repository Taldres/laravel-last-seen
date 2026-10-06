<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Tests\TestModels\QueuedActivityListener;
use Taldres\LastSeen\Tests\TestModels\UntrackedUser;
use Taldres\LastSeen\Tests\TestModels\User;

it('updates last_seen_at when the event is dispatched manually', function () {
    $user = User::create(['email' => fake()->email()]);

    Event::dispatch(new UserWasActiveEvent($user));

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('ignores users that do not use the LastSeen trait', function () {
    Event::dispatch(new UserWasActiveEvent(new GenericUser(['id' => 1])));
})->throwsNoExceptions();

it('subscribes to the event once', function () {
    expect(Event::getListeners(UserWasActiveEvent::class))->toHaveCount(1);
});

it('does not write for models without the LastSeen trait', function () {
    $user = UntrackedUser::create(['email' => fake()->email()]);

    Event::dispatch(new UserWasActiveEvent($user));

    expect(DB::table('users')->where('id', $user->id)->value('last_seen_at'))->toBeNull();
});

it('does not query the database for users that do not exist in it', function () {
    $user = new User(['email' => fake()->email()]);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Event::dispatch(new UserWasActiveEvent($user));

    expect($queries)->toBe(0)
        ->and($user->last_seen_at)->toBeNull();
});

it('does not write when tracking is not allowed', function (string $reason) {
    match ($reason) {
        'disabled' => config(['last-seen.enabled' => false]),
        'rejected' => LastSeen::trackUsing(fn () => false),
    };

    $user = User::create(['email' => fake()->email()]);

    Event::dispatch(new UserWasActiveEvent($user));

    expect($user->fresh()->last_seen_at)->toBeNull();
})->with([
    'package disabled' => ['disabled'],
    'rejected by trackUsing' => ['rejected'],
]);

it('does not dispatch events while recording', function () {
    $user = User::create(['email' => fake()->email()]);
    $events = [];
    Event::listen('*', function (string $event) use (&$events) {
        $events[] = $event;
    });

    expect(LastSeen::record($user))->toBeTrue();

    $this->travel(61)->seconds();

    expect($user->updateLastSeenAt())->toBeTrue()
        ->and(array_filter($events, fn (string $event) => $event === UserWasActiveEvent::class || str_starts_with($event, 'eloquent.')))
        ->toBe([]);
});

it('does not dispatch the event again when a listener records the user', function () {
    $user = User::create(['email' => fake()->email()]);
    $dispatched = 0;

    Event::listen(UserWasActiveEvent::class, function (UserWasActiveEvent $event) use (&$dispatched) {
        $dispatched++;
        LastSeen::record($event->user);
    });

    Event::dispatch(new UserWasActiveEvent($user));

    expect($dispatched)->toBe(1)
        ->and($user->fresh()->last_seen_at)->not->toBeNull();
});

it('passes a user restored from the database to queued listeners', function () {
    QueuedActivityListener::$users = [];
    Event::listen(UserWasActiveEvent::class, QueuedActivityListener::class);
    $user = User::create(['email' => fake()->email()]);

    try {
        Event::dispatch(new UserWasActiveEvent($user));

        expect(QueuedActivityListener::$users)->toHaveCount(1)
            ->and(QueuedActivityListener::$users[0])->not->toBe($user)
            ->and(QueuedActivityListener::$users[0]->is($user))->toBeTrue()
            ->and(QueuedActivityListener::$users[0]->last_seen_at)->not->toBeNull();
    } finally {
        QueuedActivityListener::$users = [];
    }
});

it('resolves the manager for each event, so a mocked facade receives the call', function () {
    $user = User::create(['email' => fake()->email()]);

    LastSeen::shouldReceive('record')->once()->with($user)->andReturn(true);

    Event::dispatch(new UserWasActiveEvent($user));

    expect($user->fresh()->last_seen_at)->toBeNull();
});
