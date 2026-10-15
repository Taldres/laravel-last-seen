<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function createUsersTable(): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });
}

function createUnixTimestampUsersTable(): void
{
    Schema::create('unix_timestamp_users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->unsignedInteger('last_seen_at')->nullable();
        $table->unsignedInteger('created_at')->nullable();
        $table->unsignedInteger('updated_at')->nullable();
    });
}

function skipUnlessDatabaseStoresDateOffsets(): void
{
    $connection = DB::connection();

    $stores = match ($connection->getDriverName()) {
        'mariadb', 'sqlsrv' => false,
        'mysql' => version_compare((string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), '8.0.19', '>='),
        default => true,
    };

    if (! $stores) {
        test()->markTestSkipped('This database cannot store a date with a timezone offset in a datetime column.');
    }
}

/**
 * Runs the callback with the given environment variables set, the way the .env file sets them, and restores the
 * previous values afterwards.
 *
 * @template TResult
 *
 * @param  array<string, string>  $variables
 * @param  Closure(): TResult  $callback
 * @return TResult
 */
function withEnvironment(array $variables, Closure $callback): mixed
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $name => [$env, $server]) {
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
    }
}
