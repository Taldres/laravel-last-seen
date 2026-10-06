<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestModels\SelfReferencingUser;
use Taldres\LastSeen\Tests\TestModels\UnixTimestampUser;
use Taldres\LastSeen\Tests\TestModels\User;
use Taldres\LastSeen\Tests\TestModels\UtcStoredUser;

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('qualifies last_seen_at so a joined table with the same column does not clash', function () {
    Schema::create('devices', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->timestamp('last_seen_at')->nullable();
    });

    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);

    DB::table('devices')->insert([
        ['user_id' => $recent->id, 'last_seen_at' => now()->subDay()],
        ['user_id' => $stale->id, 'last_seen_at' => now()],
    ]);

    $ids = User::query()
        ->select('users.*')
        ->join('devices', 'devices.user_id', '=', 'users.id')
        ->onlyRecentlySeen()
        ->pluck('users.id')
        ->all();

    expect($ids)->toBe([$recent->id]);
});

it('compares in the storage format of the model, like recentlySeen()', function (string $model, string $timezone) {
    createUnixTimestampUsersTable();
    date_default_timezone_set($timezone);
    $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

    $recent = $model::create(['email' => fake()->email()])->forceFill(['last_seen_at' => now()->subMinutes(2)]);
    $recent->save();
    $stale = $model::create(['email' => fake()->email()])->forceFill(['last_seen_at' => now()->subHour()]);
    $stale->save();

    expect($recent->fresh()->recentlySeen())->toBeTrue()
        ->and($stale->fresh()->recentlySeen())->toBeFalse()
        ->and($model::onlyRecentlySeen()->pluck('id')->all())->toBe([$recent->id]);
})->with([
    'unix timestamp date format' => [UnixTimestampUser::class, 'UTC'],
    'cast that stores UTC in a non-UTC app' => [UtcStoredUser::class, 'Europe/Berlin'],
]);

it('agrees with recentlySeen() at the threshold when the current time has microseconds', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:05:00.999999'));

    $atThreshold = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 12:00:00'])->fresh();
    $pastThreshold = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => '2026-10-06 11:59:59'])->fresh();

    expect($atThreshold->recentlySeen())->toBeTrue()
        ->and($pastThreshold->recentlySeen())->toBeFalse()
        ->and(User::onlyRecentlySeen()->pluck('id')->all())->toBe([$atThreshold->id]);
});

it('keeps its conditions apart from a preceding where and orWhere', function () {
    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);

    $ids = User::query()
        ->where('email', $recent->email)
        ->orWhere('email', $stale->email)
        ->onlyRecentlySeen()
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$recent->id]);
});

it('can be negated to find stale and never seen users', function () {
    User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    $neverSeen = User::create(['email' => fake()->email()]);

    $ids = User::query()
        ->whereNot(fn (Builder $query) => $query->onlyRecentlySeen())
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$stale->id, $neverSeen->id]);
});

it('works inside whereHas on a self-referencing relation', function () {
    Schema::table('users', function (Blueprint $table) {
        $table->foreignId('referrer_id')->nullable();
    });

    $recent = SelfReferencingUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $stale = SelfReferencingUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);
    $referredByRecent = SelfReferencingUser::create(['email' => fake()->email(), 'referrer_id' => $recent->id]);
    SelfReferencingUser::create(['email' => fake()->email(), 'referrer_id' => $stale->id]);

    $ids = SelfReferencingUser::query()
        ->whereHas('referrer', fn (Builder $query) => $query->onlyRecentlySeen())
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$referredByRecent->id]);
});
