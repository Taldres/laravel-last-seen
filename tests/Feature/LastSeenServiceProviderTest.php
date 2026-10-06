<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use SplFileInfo;
use Taldres\LastSeen\Facades\LastSeen;
use Taldres\LastSeen\LastSeenManager;
use Taldres\LastSeen\LastSeenServiceProvider;
use Taldres\LastSeen\Tests\TestModels\User;

beforeEach(function () {
    $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'last-seen-[database]-'.Str::random(8);
    $this->migrations = $this->databasePath.DIRECTORY_SEPARATOR.'migrations';

    File::ensureDirectoryExists($this->migrations);
    $this->app->useDatabasePath($this->databasePath);

    $provider = $this->app->getProvider(LastSeenServiceProvider::class);
    $this->migrationFileName = fn () => (new ReflectionMethod($provider, 'getMigrationFileName'))
        ->invoke($provider, 'add_last_seen_at_to_users_table.php');
});

afterEach(function () {
    File::deleteDirectory($this->databasePath);
});

it('names a new migration after the current time', function () {
    $this->freezeSecond();

    expect(($this->migrationFileName)())->toBe(
        $this->migrations.DIRECTORY_SEPARATOR.now()->format('Y_m_d_His').'_add_last_seen_at_to_users_table.php',
    );
});

it('reuses a published migration, also when its path contains glob characters', function () {
    File::put($existing = $this->migrations.DIRECTORY_SEPARATOR.'2020_01_01_000000_add_last_seen_at_to_users_table.php', '<?php');

    expect(($this->migrationFileName)())->toBe($existing);
});

it('keeps one manager with its trackUsing callback for the lifetime of the application', function () {
    $manager = app(LastSeenManager::class);
    LastSeen::trackUsing(fn () => false);

    $this->app->forgetScopedInstances();
    Facade::clearResolvedInstances();

    expect(app(LastSeenManager::class))->toBe($manager)
        ->and(LastSeen::shouldTrack(User::create(['email' => fake()->email()])))->toBeFalse();
});

it('merges the default configuration', function () {
    expect(config('last-seen'))->toBe([
        'models' => ['user' => 'App\\Models\\User'],
        'enabled' => true,
        'update_threshold' => 60,
        'recently_seen_threshold' => 300,
    ]);
});

describe('publishing', function () {
    beforeEach(function () {
        $this->publishes = ServiceProvider::$publishes;
        $this->publishGroups = ServiceProvider::$publishGroups;

        $this->configPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'last-seen-config-'.Str::random(8);
        File::ensureDirectoryExists($this->configPath);
        $this->app->useConfigPath($this->configPath);

        $this->packagePath = fn (string ...$segments): string => implode(DIRECTORY_SEPARATOR, [dirname(__DIR__, 2), ...$segments]);
        $this->publishedConfig = $this->configPath.DIRECTORY_SEPARATOR.'last-seen.php';
        $this->publishedMigration = $this->migrations.DIRECTORY_SEPARATOR.'2026_10_06_120000_add_last_seen_at_to_users_table.php';

        $this->publishedFiles = fn (): array => collect([...File::allFiles($this->configPath), ...File::allFiles($this->databasePath)])
            ->map(fn (SplFileInfo $file) => $file->getPathname())
            ->all();

        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        (new LastSeenServiceProvider($this->app))->boot();
    });

    afterEach(function () {
        ServiceProvider::$publishes = $this->publishes;
        ServiceProvider::$publishGroups = $this->publishGroups;
        File::deleteDirectory($this->configPath);
    });

    it('publishes only the config file for a config tag', function (string $tag) {
        $this->artisan('vendor:publish', ['--tag' => $tag])->assertSuccessful()->run();

        expect(($this->publishedFiles)())->toBe([$this->publishedConfig])
            ->and(File::get($this->publishedConfig))->toBe(File::get(($this->packagePath)('config', 'last-seen.php')));
    })->with(['last-seen-config', 'config']);

    it('publishes only the migration for a migration tag', function (string $tag) {
        $this->artisan('vendor:publish', ['--tag' => $tag])->assertSuccessful()->run();

        expect(($this->publishedFiles)())->toBe([$this->publishedMigration])
            ->and(File::get($this->publishedMigration))
            ->toBe(File::get(($this->packagePath)('database', 'migrations', 'add_last_seen_at_to_users_table.php.stub')));
    })->with(['last-seen-migrations', 'migrations']);

    it('publishes the config file and the migration for the provider', function () {
        $this->artisan('vendor:publish', ['--provider' => LastSeenServiceProvider::class])->assertSuccessful()->run();

        expect(($this->publishedFiles)())->toBe([$this->publishedConfig, $this->publishedMigration]);
    });

    it('does not publish the migration again in a later run', function (bool $force) {
        $this->artisan('vendor:publish', ['--tag' => 'last-seen-migrations'])->assertSuccessful()->run();
        File::put($this->publishedMigration, '<?php // changed');

        $this->travel(5)->minutes();
        (new LastSeenServiceProvider($this->app))->boot();

        $this->artisan('vendor:publish', ['--tag' => 'last-seen-migrations', '--force' => $force])->assertSuccessful()->run();

        expect(($this->publishedFiles)())->toBe([$this->publishedMigration])
            ->and(File::get($this->publishedMigration))->toBe($force
                ? File::get(($this->packagePath)('database', 'migrations', 'add_last_seen_at_to_users_table.php.stub'))
                : '<?php // changed');
    })->with(['without force' => false, 'with force' => true]);

    it('registers nothing to publish outside the console', function () {
        ServiceProvider::$publishes = [];
        ServiceProvider::$publishGroups = [];

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturnFalse();

        (new LastSeenServiceProvider($app))->boot();

        expect(ServiceProvider::$publishes)->toBe([])
            ->and(ServiceProvider::$publishGroups)->toBe([]);
    });
});
