<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestModels\IsoFormatUser;
use Taldres\LastSeen\Tests\TestModels\MicrosecondUser;
use Taldres\LastSeen\Tests\TestModels\SoftDeletingUser;
use Taldres\LastSeen\Tests\TestModels\UnixTimestampUser;
use Taldres\LastSeen\Tests\TestModels\User;
use Taldres\LastSeen\Tests\TestModels\UtcStoredUser;

beforeEach(function () {
    createUnixTimestampUsersTable();

    Schema::create('microsecond_users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at', 6)->nullable();
        $table->timestamps(6);
    });
});

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('finds stale and never seen users with notRecentlySeen() as the exact opposite of onlyRecentlySeen()', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:05:00.999999'));

    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $atThreshold = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:00:00']);
    $inTheFuture = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->addHour()]);
    $pastThreshold = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:59:59']);
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    $neverSeen = User::create(['email' => fake()->email()]);

    expect(User::notRecentlySeen()->orderBy('id')->pluck('id')->all())
        ->toBe([$pastThreshold->id, $stale->id, $neverSeen->id])
        ->and(User::onlyRecentlySeen()->orderBy('id')->pluck('id')->all())
        ->toBe([$recent->id, $atThreshold->id, $inTheFuture->id])
        ->and(User::all()->filter->recentlySeen()->pluck('id')->all())
        ->toBe([$recent->id, $atThreshold->id, $inTheFuture->id]);
});

it('keeps the conditions of notRecentlySeen() apart from a preceding where and orWhere', function () {
    $stale = User::forceCreate(['email' => 'stale@example.com', 'last_seen_at' => now()->subDay()]);
    User::forceCreate(['email' => 'recent@example.com', 'last_seen_at' => now()]);
    User::forceCreate(['email' => 'other@example.com', 'last_seen_at' => now()->subDay()]);

    $ids = User::query()
        ->where('email', 'stale@example.com')
        ->orWhere('email', 'recent@example.com')
        ->notRecentlySeen()
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$stale->id]);
});

it('finds only users who were never seen with neverSeen()', function () {
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subYear()]);
    $neverSeen = User::create(['email' => fake()->email()]);
    $forgotten = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $forgotten->forgetLastSeenAt();

    expect(User::neverSeen()->orderBy('id')->pluck('id')->all())->toBe([$neverSeen->id, $forgotten->id]);
});

it('finds users seen before a moment with lastSeenBefore(), without users who were never seen', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $longAgo = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(40)]);
    $justBefore = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(30)->subSecond()]);
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(30)]);
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(10)]);
    User::create(['email' => fake()->email()]);

    expect(User::lastSeenBefore(now()->subDays(30))->orderBy('id')->pluck('id')->all())
        ->toBe([$longAgo->id, $justBefore->id]);
});

it('includes users who were never seen when lastSeenBefore() is negated', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(40)]);
    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    $neverSeen = User::create(['email' => fake()->email()]);

    $ids = User::query()
        ->whereNot(fn (Builder $query) => $query->lastSeenBefore(now()->subDays(30)))
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$recent->id, $neverSeen->id]);
});

it('combines lastSeenBefore() with neverSeen() to find everyone inactive for a while', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $longAgo = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(40)]);
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    $neverSeen = User::create(['email' => fake()->email()]);

    $ids = User::query()
        ->where(fn (Builder $query) => $query->neverSeen()->orWhere->lastSeenBefore(now()->subDays(30)))
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$longAgo->id, $neverSeen->id]);
});

it('compares lastSeenBefore() in the application timezone, whatever timezone the moment has', function (callable $moment) {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

    $before = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:29:59']);
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:30:00']);

    expect(User::lastSeenBefore($moment())->pluck('id')->all())->toBe([$before->id]);
})->with([
    'carbon in the application timezone' => [fn () => Carbon::parse('2026-10-06 11:30:00', 'UTC')],
    'carbon in another timezone' => [fn () => Carbon::parse('2026-10-06 20:30:00', 'Asia/Tokyo')],
    'immutable carbon in another timezone' => [fn () => CarbonImmutable::parse('2026-10-06 07:30:00', 'America/New_York')],
    'native immutable date' => [fn () => new DateTimeImmutable('2026-10-06 11:30:00+00:00')],
]);

it('compares lastSeenBefore() in the precision of the stored column', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00.400000'));
    $user = User::create(['email' => fake()->email()]);
    $user->updateLastSeenAt();

    expect(User::lastSeenBefore(now())->count())->toBe(0)
        ->and(User::lastSeenBefore(now()->addSecond())->count())->toBe(1);
});

it('does not change the moment passed to lastSeenBefore()', function () {
    $moment = Carbon::parse('2026-10-06 20:30:00', 'Asia/Tokyo');

    User::lastSeenBefore($moment)->toBase();

    expect($moment->getTimezone()->getName())->toBe('Asia/Tokyo')
        ->and($moment->format('Y-m-d H:i:s'))->toBe('2026-10-06 20:30:00');
});

it('filters in the storage format of the model, like recentlySeen()', function (string $model, string $timezone) {
    date_default_timezone_set($timezone);
    $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

    $recent = $model::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subMinutes(2)]);
    $stale = $model::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subHour()]);
    $neverSeen = $model::forceCreate(['email' => fake()->email()]);

    expect($model::notRecentlySeen()->orderBy('id')->pluck('id')->all())->toBe([$stale->id, $neverSeen->id])
        ->and($model::onlyRecentlySeen()->pluck('id')->all())->toBe([$recent->id])
        ->and($model::neverSeen()->pluck('id')->all())->toBe([$neverSeen->id])
        ->and($model::lastSeenBefore(now()->subMinutes(30))->pluck('id')->all())->toBe([$stale->id])
        ->and($model::lastSeenBefore(now()->subMinute())->orderBy('id')->pluck('id')->all())->toBe([$recent->id, $stale->id])
        ->and($model::lastSeenBefore(now()->subHours(2))->count())->toBe(0);
})->with([
    'unix timestamp date format' => [UnixTimestampUser::class, 'UTC'],
    'microseconds' => [MicrosecondUser::class, 'UTC'],
    'ISO 8601' => [IsoFormatUser::class, 'UTC'],
    'cast that stores UTC in a non-UTC app' => [UtcStoredUser::class, 'Europe/Berlin'],
    'default format in a non-UTC app' => [User::class, 'Europe/Berlin'],
]);

it('qualifies last_seen_at so a joined table with the same column does not clash', function () {
    Schema::create('devices', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->timestamp('last_seen_at')->nullable();
    });

    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDays(2)]);
    $neverSeen = User::create(['email' => fake()->email()]);

    DB::table('devices')->insert([
        ['user_id' => $recent->id, 'last_seen_at' => now()->subDays(2)],
        ['user_id' => $stale->id, 'last_seen_at' => now()],
        ['user_id' => $neverSeen->id, 'last_seen_at' => now()],
    ]);

    $joined = fn () => User::query()
        ->select('users.*')
        ->join('devices', 'devices.user_id', '=', 'users.id')
        ->orderBy('users.id');

    expect($joined()->notRecentlySeen()->pluck('users.id')->all())->toBe([$stale->id, $neverSeen->id])
        ->and($joined()->neverSeen()->pluck('users.id')->all())->toBe([$neverSeen->id])
        ->and($joined()->lastSeenBefore(now()->subDay())->pluck('users.id')->all())->toBe([$stale->id]);
});

it('skips trashed users like any other query', function () {
    Schema::create('soft_deleting_users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $stale = SoftDeletingUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    $neverSeen = SoftDeletingUser::forceCreate(['email' => fake()->email()]);
    $trashed = SoftDeletingUser::forceCreate(['email' => fake()->email()]);
    $trashed->delete();

    expect(SoftDeletingUser::notRecentlySeen()->orderBy('id')->pluck('id')->all())->toBe([$stale->id, $neverSeen->id])
        ->and(SoftDeletingUser::neverSeen()->pluck('id')->all())->toBe([$neverSeen->id])
        ->and(SoftDeletingUser::withTrashed()->neverSeen()->orderBy('id')->pluck('id')->all())->toBe([$neverSeen->id, $trashed->id])
        ->and(SoftDeletingUser::lastSeenBefore(now())->pluck('id')->all())->toBe([$stale->id]);
});
