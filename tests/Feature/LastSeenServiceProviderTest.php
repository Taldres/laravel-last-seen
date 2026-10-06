<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionMethod;
use Taldres\LastSeen\LastSeenServiceProvider;

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
