<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Taldres\LastSeen\Tests\TestModels\AbstractUser;
use Taldres\LastSeen\Tests\TestModels\RenamedUser;
use Taldres\LastSeen\Tests\TestModels\SecondaryConnectionUser;
use Taldres\LastSeen\Tests\TestModels\User;

beforeEach(function () {
    config(['last-seen.models.user' => User::class]);

    $this->migration = require __DIR__.'/../../database/migrations/add_last_seen_at_to_users_table.php.stub';
});

it('adds and drops the last_seen_at column on the configured user table', function () {
    $this->migration->down();
    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeFalse();

    $this->migration->up();
    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue()
        ->and(Schema::hasIndex('users', ['last_seen_at']))->toBeTrue();

    $this->migration->down();
    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeFalse();
});

it('refuses to take over an existing last_seen_at column', function () {
    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class);

    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue();
});

it('rejects a user model configuration that is not a concrete Eloquent model', function (mixed $model) {
    config(['last-seen.models.user' => $model]);

    expect(fn () => $this->migration->up())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->migration->down())->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [null],
    'unknown class' => ['App\\Models\\Missing'],
    'not a model' => [stdClass::class],
    'abstract model' => [AbstractUser::class],
]);

it('runs on the connection of the user model', function () {
    config([
        'database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'last-seen.models.user' => SecondaryConnectionUser::class,
    ]);
    Schema::connection('secondary')->create('users', function (Blueprint $table) {
        $table->id();
    });

    $this->migration->up();

    expect($this->migration->getConnection())->toBe('secondary')
        ->and(Schema::connection('secondary')->hasColumn('users', 'last_seen_at'))->toBeTrue();

    $this->migration->down();

    expect(Schema::connection('secondary')->hasColumn('users', 'last_seen_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue();
});

it('drops the column in down() after the table was renamed', function () {
    $this->migration->down();
    $this->migration->up();

    Schema::rename('users', 'accounts');
    config(['last-seen.models.user' => RenamedUser::class]);

    $this->migration->down();

    expect(Schema::hasColumn('accounts', 'last_seen_at'))->toBeFalse()
        ->and(Schema::getIndexes('accounts'))->each(fn ($index) => $index->columns->not->toContain('last_seen_at'));
});
