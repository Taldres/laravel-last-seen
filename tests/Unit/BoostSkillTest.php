<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Unit;

use Symfony\Component\Yaml\Yaml;

it('ships a Boost skill with the frontmatter Boost requires', function () {
    $directory = __DIR__.'/../../resources/boost/skills/laravel-last-seen-development';

    preg_match('/\A---\R(.*?)\R---\R/s', (string) file_get_contents($directory.'/SKILL.md'), $matches);

    $frontmatter = Yaml::parse($matches[1] ?? '');

    expect($frontmatter)->toBeArray()
        ->and($frontmatter['name'] ?? null)->toBe(basename($directory))
        ->and($frontmatter['description'] ?? null)->toBeString()->not->toBeEmpty();
});
