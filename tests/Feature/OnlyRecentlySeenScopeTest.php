<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
