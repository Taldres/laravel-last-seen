<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Unit;

use Symfony\Component\Yaml\Yaml;

it('ships a Boost skill with the frontmatter Boost requires', function (string $skill) {
    $directory = __DIR__.'/../../resources/boost/skills/'.$skill;

    preg_match('/\A---\R(.*?)\R---\R/s', (string) file_get_contents($directory.'/SKILL.md'), $matches);

    $frontmatter = Yaml::parse($matches[1] ?? '');

    expect($frontmatter)->toBeArray()
        ->and($frontmatter['name'] ?? null)->toBe(basename($directory))
        ->and($frontmatter['description'] ?? null)->toBeString()->not->toBeEmpty();
})->with([
    'laravel-last-seen-development',
    'upgrade-laravel-last-seen-v1',
]);

it('ships a short Boost guideline that points to the development skill', function () {
    $guideline = (string) file_get_contents(__DIR__.'/../../resources/boost/guidelines/core.blade.php');

    expect($guideline)->toStartWith('## Laravel Last Seen')
        ->toContain('laravel-last-seen-development')
        ->and(strlen($guideline))->toBeLessThan(1000);
});
