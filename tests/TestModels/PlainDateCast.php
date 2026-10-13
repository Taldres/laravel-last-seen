<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<DateTimeImmutable|null, DateTimeInterface|null>
 */
class PlainDateCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }
}
