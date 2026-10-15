<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Unit;

use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;

it('defaults to the documented thresholds', function () {
    expect(LastSeenDefaultThreshold::Update->value)->toBe(60)
        ->and(LastSeenDefaultThreshold::RecentlySeen->value)->toBe(300);
});

it('reads a configuration value as seconds', function (mixed $value, ?int $seconds) {
    expect(LastSeenDefaultThreshold::Update->seconds($value))->toBe($seconds);
})->with([
    'integer' => [15, 15],
    'PHP_INT_MAX' => [PHP_INT_MAX, PHP_INT_MAX],
    'zero' => [0, 0],
    'negative integer' => [-1, -1],
    'digits from the .env file' => ['15', 15],
    'empty string' => ['', 60],
    'text' => ['banana', null],
    'many digits' => ['9999999999', 9999999999],
    'zeros only' => ['000', 0],
    'PHP_INT_MAX as digits' => ['9223372036854775807', PHP_INT_MAX],
    'one above PHP_INT_MAX' => ['9223372036854775808', null],
    'overflowing digits' => ['99999999999999999999', null],
    'whitespace' => [' 60', null],
    'exponent' => ['6e1', null],
    'signed string' => ['-1', null],
    'decimal string' => ['1.5', null],
    'float' => [60.0, null],
    'boolean' => [true, null],
    'null' => [null, null],
]);

it('uses the default of the threshold for an empty string', function () {
    expect(LastSeenDefaultThreshold::RecentlySeen->seconds(''))->toBe(300);
});

it('converts .env values for configuration files published with 1.6.1', function (mixed $value, mixed $expected) {
    expect(LastSeenDefaultThreshold::Update->fromEnv($value))->toBe($expected);
})->with([
    'digits' => ['15', 15],
    'empty string' => ['', 60],
    'missing' => [null, 60],
    'invalid values pass through' => ['banana', 'banana'],
    'one above PHP_INT_MAX passes through' => ['9223372036854775808', '9223372036854775808'],
    'overflowing digits pass through' => ['99999999999999999999', '99999999999999999999'],
]);
