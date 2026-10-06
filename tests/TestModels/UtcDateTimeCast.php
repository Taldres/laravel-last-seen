<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|null>
 */
class UtcDateTimeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return is_string($value)
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone(date_default_timezone_get())
            : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s')
            : null;
    }
}
