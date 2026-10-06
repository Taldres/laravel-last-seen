<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Taldres\LastSeen\Tests\TestModels\AbstractUser;
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
