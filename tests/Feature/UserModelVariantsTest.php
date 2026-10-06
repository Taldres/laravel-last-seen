<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\Tests\TestModels\CastsMethodUser;
use Taldres\LastSeen\Tests\TestModels\CustomKeyMember;
use Taldres\LastSeen\Tests\TestModels\CustomTimestampColumnsUser;
use Taldres\LastSeen\Tests\TestModels\IsoFormatUser;
use Taldres\LastSeen\Tests\TestModels\MicrosecondUser;
use Taldres\LastSeen\Tests\TestModels\NoUpdatedAtUser;
use Taldres\LastSeen\Tests\TestModels\SecondaryConnectionUser;
use Taldres\LastSeen\Tests\TestModels\SoftDeletingUser;
use Taldres\LastSeen\Tests\TestModels\StringKeyUser;
use Taldres\LastSeen\Tests\TestModels\UlidUser;
use Taldres\LastSeen\Tests\TestModels\User;
use Taldres\LastSeen\Tests\TestModels\UuidUser;

beforeEach(function () {
    Schema::create('uuid_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });

    Schema::create('ulid_users', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });

    Schema::create('string_key_users', function (Blueprint $table) {
        $table->string('handle')->primary();
        $table->timestamp('last_seen_at')->nullable();
    });

    Schema::create('members', function (Blueprint $table) {
        $table->unsignedBigInteger('member_no')->primary();
        $table->timestamp('last_seen_at')->nullable();
    });

    Schema::create('soft_deleting_users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('custom_timestamp_users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamp('registered_at')->nullable();
        $table->timestamp('modified_at')->nullable();
    });
});

afterEach(function () {
    date_default_timezone_set('UTC');
    DB::purge('secondary');
});

it('records, scopes and forgets only the user with the given key', function (string $model, array $attributes, array $otherAttributes) {
    $user = $model::forceCreate($attributes);
    $other = $model::forceCreate([...$otherAttributes, 'last_seen_at' => now()->subHour()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and($model::onlyRecentlySeen()->get()->modelKeys())->toBe([$user->getKey()]);

    LastSeen::forget($user);

    expect($user->fresh()->last_seen_at)->toBeNull()
        ->and($other->fresh()->last_seen_at)->not->toBeNull();
})->with([
    'uuid key' => [UuidUser::class, ['email' => 'first@example.com'], ['email' => 'second@example.com']],
    'ulid key' => [UlidUser::class, ['email' => 'first@example.com'], ['email' => 'second@example.com']],
    'custom non-incrementing key' => [CustomKeyMember::class, ['member_no' => 1001], ['member_no' => 1002]],
    'numeric-looking string key' => [StringKeyUser::class, ['handle' => '007'], ['handle' => '7']],
]);

it('records, scopes and forgets on the connection of the model, including its table prefix', function () {
    config(['database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_']]);
    Schema::connection('secondary')->create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });

    $default = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subHour()]);
    $secondary = SecondaryConnectionUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subHour()]);

    expect($secondary->id)->toBe($default->id)
        ->and(LastSeen::record($secondary))->toBeTrue()
        ->and(SecondaryConnectionUser::onlyRecentlySeen()->pluck('id')->all())->toBe([$secondary->id])
        ->and(User::onlyRecentlySeen()->count())->toBe(0);

    LastSeen::forget($secondary);

    expect($secondary->fresh()->last_seen_at)->toBeNull()
        ->and($default->fresh()->last_seen_at)->not->toBeNull();
});

it('records, scopes and forgets on a connection chosen with on()', function () {
    config(['database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    Schema::connection('secondary')->create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
    });

    $default = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subHour()]);
    $user = User::on('secondary')->forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subHour()]);

    expect($user->id)->toBe($default->id)
        ->and(LastSeen::record($user))->toBeTrue()
        ->and(User::on('secondary')->onlyRecentlySeen()->pluck('id')->all())->toBe([$user->id])
        ->and(User::onlyRecentlySeen()->count())->toBe(0);

    LastSeen::forget($user);

    expect($user->fresh()->last_seen_at)->toBeNull()
        ->and($default->fresh()->last_seen_at)->not->toBeNull();
});

it('records and forgets a soft-deleted user, while the scope skips trashed users', function () {
    $user = SoftDeletingUser::forceCreate(['email' => fake()->email()]);
    $user->delete();

    expect(LastSeen::record($user))->toBeTrue()
        ->and(SoftDeletingUser::onlyRecentlySeen()->count())->toBe(0)
        ->and(SoftDeletingUser::withTrashed()->onlyRecentlySeen()->pluck('id')->all())->toBe([$user->id]);

    LastSeen::forget($user);

    expect(SoftDeletingUser::withTrashed()->findOrFail($user->id)->last_seen_at)->toBeNull();
});

it('stores last_seen_at in the date format of the model and checks the threshold in that format', function (string $model, string $stored) {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00.250000'));

    $user = $model::forceCreate(['email' => fake()->email()]);
    $stale = $model::query()->findOrFail($user->id);

    expect(LastSeen::record($user))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('last_seen_at'))->toBe($stored);

    $this->travel(5)->seconds();
    expect(LastSeen::record($stale))->toBeFalse();

    $this->travel(56)->seconds();
    expect(LastSeen::record($stale))->toBeTrue();
})->with([
    'microseconds' => [MicrosecondUser::class, '2026-10-06 12:00:00.250000'],
    'ISO 8601' => [IsoFormatUser::class, '2026-10-06T12:00:00+00:00'],
]);

it('agrees with recentlySeen() in the scope for the date format of the model', function (string $model) {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    $recent = $model::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subMinutes(2)]);
    $stale = $model::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subHours(2)]);

    expect($recent->fresh()->recentlySeen())->toBeTrue()
        ->and($stale->fresh()->recentlySeen())->toBeFalse()
        ->and($model::onlyRecentlySeen()->pluck('id')->all())->toBe([$recent->id]);
})->with([
    'microseconds' => [MicrosecondUser::class],
    'ISO 8601' => [IsoFormatUser::class],
]);

it('agrees with recentlySeen() at the boundary when microseconds are stored', function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:10:00.500000'));
    $since = LastSeen::recentlySeenSince();

    $atBoundary = MicrosecondUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => $since]);
    $justBefore = MicrosecondUser::forceCreate(['email' => fake()->email(), 'last_seen_at' => $since->copy()->subMicrosecond()]);

    expect($atBoundary->fresh()->recentlySeen())->toBeTrue()
        ->and($justBefore->fresh()->recentlySeen())->toBeFalse()
        ->and(MicrosecondUser::onlyRecentlySeen()->pluck('id')->all())->toBe([$atBoundary->id]);
});

it('never touches the timestamp columns of the model', function (string $model, string $table, array $columns) {
    $user = $model::forceCreate(['email' => fake()->email()]);
    $timestamps = (array) DB::table($table)->where('id', $user->id)->first($columns);

    $this->travel(2)->minutes();

    expect(LastSeen::record($user))->toBeTrue();

    LastSeen::forget($user);

    expect($timestamps[$columns[0]])->not->toBeNull()
        ->and((array) DB::table($table)->where('id', $user->id)->first($columns))->toBe($timestamps)
        ->and($user->isDirty())->toBeFalse();
})->with([
    'custom created and updated columns' => [CustomTimestampColumnsUser::class, 'custom_timestamp_users', ['registered_at', 'modified_at']],
    'no updated_at column' => [NoUpdatedAtUser::class, 'users', ['created_at', 'updated_at']],
]);

it('stores the wall time of a non-UTC app timezone, also with an immutable_datetime cast', function () {
    date_default_timezone_set('Europe/Berlin');
    $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

    $user = CastsMethodUser::forceCreate(['email' => fake()->email()]);

    expect(LastSeen::record($user))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('last_seen_at'))->toBe('2026-07-01 12:00:00')
        ->and($user->fresh()->last_seen_at->equalTo(now()))->toBeTrue()
        ->and($user->fresh()->recentlySeen())->toBeTrue()
        ->and(CastsMethodUser::onlyRecentlySeen()->count())->toBe(1);

    $this->travel(301)->seconds();

    expect($user->fresh()->recentlySeen())->toBeFalse()
        ->and(CastsMethodUser::onlyRecentlySeen()->count())->toBe(0)
        ->and(LastSeen::record($user->fresh()))->toBeTrue();
});

it('records and reports correctly across the DST spring-forward gap', function () {
    date_default_timezone_set('Europe/Berlin');
    $this->travelTo(Carbon::parse('2026-03-29 00:59:30', 'UTC'));

    $user = User::forceCreate(['email' => fake()->email()]);
    $stale = User::query()->findOrFail($user->id);
    LastSeen::record($user);

    $this->travelTo(Carbon::parse('2026-03-29 01:00:20', 'UTC'));
    expect(LastSeen::record($stale))->toBeFalse();

    $this->travelTo(Carbon::parse('2026-03-29 01:00:45', 'UTC'));
    expect(LastSeen::record($stale))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('last_seen_at'))->toBe('2026-03-29 03:00:45');

    $this->travelTo(Carbon::parse('2026-03-29 01:05:45', 'UTC'));
    expect($user->fresh()->recentlySeen())->toBeTrue()
        ->and(User::onlyRecentlySeen()->count())->toBe(1);

    $this->travel(1)->seconds();
    expect($user->fresh()->recentlySeen())->toBeFalse()
        ->and(User::onlyRecentlySeen()->count())->toBe(0);
});
