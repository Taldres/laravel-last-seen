<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Taldres\LastSeen\Tests\TestModels\InheritedTrackedUser;
use Taldres\LastSeen\Tests\TestModels\TraitComposedUser;
use Taldres\LastSeen\Tests\TestModels\UntrackedUser;
use Taldres\LastSeen\Tests\TestModels\User;

/**
 * @return array<string, mixed>
 */
function aboutLastSeen(): array
{
    Artisan::call('about', ['--only' => 'last_seen', '--json' => true]);

    /** @var array{last_seen: array<string, mixed>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $about['last_seen'];
}

function aboutLastSeenForConsole(): string
{
    Artisan::call('about', ['--only' => 'last_seen']);

    return Artisan::output();
}

it('shows the settings the package runs with', function () {
    config(['last-seen.models.user' => User::class]);

    expect(aboutLastSeen())->toMatchArray([
        'enabled' => true,
        'user_model' => User::class,
        'user_model_trait' => true,
        'update_threshold' => 60,
        'recently_seen_threshold' => 300,
    ])->toHaveKey('version');

    expect(aboutLastSeenForConsole())
        ->toContain('Last Seen')
        ->toContain('ENABLED')
        ->toContain('IN USE')
        ->toContain('60 seconds')
        ->toContain('300 seconds')
        ->not->toContain('not above the update threshold');
});

it('marks a user model without the trait or a model that does not exist', function (mixed $model, ?bool $usesTrait, string $console) {
    config(['last-seen.models.user' => $model]);

    expect(aboutLastSeen()['user_model_trait'])->toBe($usesTrait)
        ->and(aboutLastSeenForConsole())->toContain($console);
})->with([
    'without the trait' => [UntrackedUser::class, false, 'MISSING'],
    'trait from a parent class' => [InheritedTrackedUser::class, true, 'IN USE'],
    'trait from another trait' => [TraitComposedUser::class, true, 'IN USE'],
    'class not found' => ['App\\Models\\Missing', null, 'MODEL NOT FOUND'],
    'null' => [null, null, 'NOT A CLASS NAME'],
]);

it('shows a configured model that is not a string without failing', function (mixed $model) {
    config(['last-seen.models.user' => $model]);

    expect(aboutLastSeen())->toMatchArray(['user_model' => $model, 'user_model_trait' => null])
        ->and(aboutLastSeenForConsole())->toContain('NOT A CLASS NAME')->toContain('MODEL NOT FOUND');
})->with([
    'array' => [[User::class]],
    'integer' => [5],
]);

it('treats a model class that fails to load as not found', function () {
    $autoloader = function (string $class): void {
        if ($class === 'Taldres\\LastSeen\\Tests\\Broken\\User') {
            throw new RuntimeException('broken model');
        }
    };
    spl_autoload_register($autoloader);
    config(['last-seen.models.user' => 'Taldres\\LastSeen\\Tests\\Broken\\User']);

    try {
        expect(aboutLastSeen()['user_model_trait'])->toBeNull()
            ->and(aboutLastSeenForConsole())->toContain('MODEL NOT FOUND');
    } finally {
        spl_autoload_unregister($autoloader);
    }
});

it('shows when the package is disabled', function () {
    config(['last-seen.enabled' => false]);

    expect(aboutLastSeen()['enabled'])->toBeFalse()
        ->and(aboutLastSeenForConsole())->toContain('OFF');
});

it('warns when the recently seen threshold is not above the update threshold', function (int $recentlySeen, bool $warns) {
    config(['last-seen.update_threshold' => 60, 'last-seen.recently_seen_threshold' => $recentlySeen]);

    expect(str_contains(aboutLastSeenForConsole(), 'not above the update threshold'))->toBe($warns);
})->with([
    'below' => [30, true],
    'equal' => [60, true],
    'above' => [61, false],
]);

it('does not compare the thresholds when the update threshold is invalid', function () {
    config(['last-seen.update_threshold' => -1, 'last-seen.recently_seen_threshold' => 30]);

    expect(aboutLastSeenForConsole())->toContain('INVALID')->toContain('30 seconds')
        ->not->toContain('not above the update threshold');
});

it('marks invalid thresholds without failing', function (mixed $update, mixed $recentlySeen) {
    config(['last-seen.update_threshold' => $update, 'last-seen.recently_seen_threshold' => $recentlySeen]);

    expect(aboutLastSeen())->toMatchArray(['update_threshold' => $update, 'recently_seen_threshold' => $recentlySeen])
        ->and(substr_count(aboutLastSeenForConsole(), 'INVALID'))->toBe(2);
})->with([
    'negative' => [-1, -5],
    'not an integer' => ['banana', null],
]);

it('reads a threshold that is still a string of digits', function () {
    config(['last-seen.update_threshold' => '90', 'last-seen.recently_seen_threshold' => '600']);

    expect(aboutLastSeenForConsole())->toContain('90 seconds')->toContain('600 seconds')->not->toContain('INVALID');
});
