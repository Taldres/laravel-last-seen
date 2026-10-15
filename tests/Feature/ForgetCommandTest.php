<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestModels\AbstractUser;
use Taldres\LastSeen\Tests\TestModels\SoftDeletingUser;
use Taldres\LastSeen\Tests\TestModels\UntrackedUser;
use Taldres\LastSeen\Tests\TestModels\User;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    config(['last-seen.models.user' => User::class]);
});

/**
 * @return array<string, int>
 */
function seedForgetUsers(): array
{
    $ids = [];

    foreach ([
        'old' => '2026-06-01 08:00:00',
        'boundary' => '2026-07-08 12:00:00',
        'recent' => '2026-10-01 08:00:00',
        'never' => null,
    ] as $name => $lastSeenAt) {
        $ids[$name] = DB::table('users')->insertGetId([
            'email' => "{$name}@example.com",
            'last_seen_at' => $lastSeenAt,
            'updated_at' => '2026-06-02 00:00:00',
        ]);
    }

    return $ids;
}

it('forgets the values older than the given days and keeps everything else', function () {
    $ids = seedForgetUsers();

    $this->artisan('last-seen:forget', ['--days' => 90])
        ->expectsOutputToContain('1 ['.User::class.'] last_seen_at values forgotten.')
        ->assertSuccessful();

    expect(DB::table('users')->where('id', $ids['old'])->value('last_seen_at'))->toBeNull()
        ->and(DB::table('users')->where('id', $ids['boundary'])->value('last_seen_at'))->not->toBeNull()
        ->and(DB::table('users')->where('id', $ids['recent'])->value('last_seen_at'))->not->toBeNull()
        ->and(DB::table('users')->where('id', $ids['never'])->value('last_seen_at'))->toBeNull()
        ->and(Carbon::parse((string) DB::table('users')->where('id', $ids['old'])->value('updated_at'))->toDateTimeString())->toBe('2026-06-02 00:00:00');
});

it('only reports what would be forgotten with --pretend', function () {
    $ids = seedForgetUsers();

    $this->artisan('last-seen:forget', ['--days' => 90, '--pretend' => true])
        ->expectsOutputToContain('1 ['.User::class.'] last_seen_at values would be forgotten.')
        ->assertSuccessful();

    expect(DB::table('users')->where('id', $ids['old'])->value('last_seen_at'))->not->toBeNull();
});

it('forgets while the package is disabled', function () {
    config(['last-seen.enabled' => false]);
    $ids = seedForgetUsers();

    $this->artisan('last-seen:forget', ['--days' => 90])->assertSuccessful();

    expect(DB::table('users')->where('id', $ids['old'])->value('last_seen_at'))->toBeNull();
});

it('forgets the values of the model given with --model, including soft deleted rows', function () {
    Schema::create('soft_deleting_users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $old = SoftDeletingUser::forceCreate(['email' => 'old@example.com']);
    $recent = SoftDeletingUser::forceCreate(['email' => 'recent@example.com']);
    DB::table('soft_deleting_users')->where('id', $old->id)->update(['last_seen_at' => '2026-06-01 08:00:00', 'deleted_at' => '2026-06-05 08:00:00']);
    DB::table('soft_deleting_users')->where('id', $recent->id)->update(['last_seen_at' => '2026-10-01 08:00:00']);
    $untouched = seedForgetUsers();

    $this->artisan('last-seen:forget', ['--days' => 90, '--model' => SoftDeletingUser::class])
        ->expectsOutputToContain('1 ['.SoftDeletingUser::class.'] last_seen_at values forgotten.')
        ->assertSuccessful();

    expect(DB::table('soft_deleting_users')->where('id', $old->id)->value('last_seen_at'))->toBeNull()
        ->and(DB::table('soft_deleting_users')->where('id', $recent->id)->value('last_seen_at'))->not->toBeNull()
        ->and(DB::table('users')->where('id', $untouched['old'])->value('last_seen_at'))->not->toBeNull();
});

it('rejects invalid input without touching the data', function (array $options, string $message) {
    $ids = seedForgetUsers();

    $this->artisan('last-seen:forget', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(DB::table('users')->where('id', $ids['old'])->value('last_seen_at'))->not->toBeNull();
})->with([
    'no age' => [[], 'Give an age of at least one year, month or day, for example --days=90.'],
    'zero days' => [['--days' => 0], 'Give an age of at least one year, month or day, for example --days=90.'],
    'all zero' => [['--years' => 0, '--months' => 0, '--days' => 0], 'Give an age of at least one year, month or day, for example --days=90.'],
    'negative days' => [['--days' => -5], 'The --days option must be a whole number from 0 to 365.'],
    'decimal days' => [['--days' => '1.5'], 'The --days option must be a whole number from 0 to 365.'],
    'text days' => [['--days' => 'many'], 'The --days option must be a whole number from 0 to 365.'],
    'too many days' => [['--days' => 366], 'The --days option must be a whole number from 0 to 365.'],
    'overflowing days' => [['--days' => '99999999999999999999'], 'The --days option must be a whole number from 0 to 365.'],
    'too many months' => [['--months' => 121], 'The --months option must be a whole number from 0 to 120.'],
    'too many years' => [['--years' => 101], 'The --years option must be a whole number from 0 to 100.'],
    'unknown model' => [['--days' => 30, '--model' => 'App\\Missing'], 'The model must be the class name of an Eloquent model.'],
    'abstract model' => [['--days' => 30, '--model' => AbstractUser::class], 'The model must be the class name of an Eloquent model.'],
    'not a model' => [['--days' => 30, '--model' => Carbon::class], 'The model must be the class name of an Eloquent model.'],
    'model without the trait' => [['--days' => 30, '--model' => UntrackedUser::class], 'does not use the LastSeen trait.'],
]);

it('forgets by months, by years and by a mix of units', function (array $options, int $forgotten) {
    seedForgetUsers();

    $this->artisan('last-seen:forget', $options)
        ->expectsOutputToContain("{$forgotten} [".User::class.'] last_seen_at values forgotten.')
        ->assertSuccessful();
})->with([
    'months' => [['--months' => 3], 1],
    'years' => [['--years' => 1], 0],
    'a mix of months and days' => [['--months' => 2, '--days' => 20], 2],
    'years with zero months' => [['--years' => 0, '--months' => 4], 1],
]);

it('does not let a month overflow into the next one', function () {
    $this->travelTo(Carbon::parse('2026-03-31 12:00:00'));
    $id = DB::table('users')->insertGetId(['email' => 'march@example.com', 'last_seen_at' => '2026-03-01 08:00:00']);

    $this->artisan('last-seen:forget', ['--months' => 1])
        ->expectsOutputToContain('0 ['.User::class.'] last_seen_at values forgotten.')
        ->assertSuccessful();

    expect(DB::table('users')->where('id', $id)->value('last_seen_at'))->not->toBeNull();
});
