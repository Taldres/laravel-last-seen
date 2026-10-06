<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Taldres\LastSeen\Tests\TestModels\AbstractUser;
use Taldres\LastSeen\Tests\TestModels\CustomKeyMember;
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

it('adds and drops the column on the custom table of the configured user model', function () {
    config(['last-seen.models.user' => CustomKeyMember::class]);
    Schema::create('members', function (Blueprint $table) {
        $table->unsignedBigInteger('member_no')->primary();
    });

    $this->migration->up();

    expect(Schema::hasColumn('members', 'last_seen_at'))->toBeTrue()
        ->and(Schema::hasIndex('members', ['last_seen_at']))->toBeTrue()
        ->and(Schema::hasIndex('users', ['last_seen_at']))->toBeFalse();

    $this->migration->down();

    expect(Schema::hasColumn('members', 'last_seen_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue();
});

it('refuses to run up() twice and keeps the column and its index', function () {
    $this->migration->down();
    $this->migration->up();

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class)
        ->and(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue()
        ->and(Schema::hasIndex('users', ['last_seen_at']))->toBeTrue();
});

it('drops the column when its index was removed manually, and down() can run twice', function () {
    $this->migration->down();
    $this->migration->up();
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex(['last_seen_at']);
    });

    $this->migration->down();
    $this->migration->down();

    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeFalse();
});

describe('run by the migrator', function () {
    beforeEach(function () {
        $this->migrationsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'last-seen-migrations-'.Str::random(8);

        File::ensureDirectoryExists($this->migrationsPath);
        File::copy(
            implode(DIRECTORY_SEPARATOR, [dirname(__DIR__, 2), 'database', 'migrations', 'add_last_seen_at_to_users_table.php.stub']),
            $this->migrationsPath.DIRECTORY_SEPARATOR.'2026_01_01_000000_add_last_seen_at_to_users_table.php',
        );
    });

    afterEach(function () {
        File::deleteDirectory($this->migrationsPath);
        DB::purge('secondary');
    });

    it('migrates and rolls back on the connection given with --database, with prefixed index names', function () {
        config([
            'database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_', 'prefix_indexes' => true],
            'last-seen.models.user' => CustomKeyMember::class,
        ]);
        Schema::connection('secondary')->create('members', function (Blueprint $table) {
            $table->unsignedBigInteger('member_no')->primary();
        });
        $options = ['--database' => 'secondary', '--path' => $this->migrationsPath, '--realpath' => true];

        $this->artisan('migrate', $options)->assertSuccessful();

        expect(Schema::connection('secondary')->hasIndex('members', 'app_members_last_seen_at_index'))->toBeTrue()
            ->and(Schema::hasTable('members'))->toBeFalse();

        $this->artisan('migrate:rollback', $options)->assertSuccessful();

        expect(Schema::connection('secondary')->hasColumn('members', 'last_seen_at'))->toBeFalse();
    });

    it('migrates on the connection of the user model and leaves it untouched with --pretend', function () {
        config([
            'database.connections.secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'last-seen.models.user' => SecondaryConnectionUser::class,
        ]);
        Schema::connection('secondary')->create('users', function (Blueprint $table) {
            $table->id();
        });
        $options = ['--path' => $this->migrationsPath, '--realpath' => true];

        $this->artisan('migrate', [...$options, '--pretend' => true])->assertSuccessful();

        expect(Schema::connection('secondary')->hasColumn('users', 'last_seen_at'))->toBeFalse();

        $this->artisan('migrate', $options)->assertSuccessful();

        expect(Schema::connection('secondary')->hasIndex('users', ['last_seen_at']))->toBeTrue()
            ->and(DB::table('migrations')->count())->toBe(1);

        $this->artisan('migrate:rollback', $options)->assertSuccessful();

        expect(Schema::connection('secondary')->hasColumn('users', 'last_seen_at'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue();
    });
});
