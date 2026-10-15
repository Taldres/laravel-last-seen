<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Enums;

enum LastSeenDefaultThreshold: int
{
    case Update = 60;
    case RecentlySeen = 300;

    /**
     * The number of seconds a configuration value stands for: an integer, a string of digits as the .env file
     * provides it, or an empty string for the default. Any other value is not a threshold and gives null.
     */
    public function seconds(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            $value === '' => $this->value,
            self::isWholeNumber($value) => (int) $value,
            default => null,
        };
    }

    /**
     * Turns a value from the .env file into the threshold the configuration holds. It is only called by
     * configuration files published with 1.6.1 and 1.6.2, which new files do not need.
     */
    public function fromEnv(mixed $value): mixed
    {
        return match (true) {
            $value === null, $value === '' => $this->value,
            self::isWholeNumber($value) => (int) $value,
            default => $value,
        };
    }

    /**
     * A string of digits that survives the conversion to an integer. A longer one would be cut off at PHP_INT_MAX.
     *
     * @phpstan-assert-if-true numeric-string $value
     */
    private static function isWholeNumber(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value) && (string) (int) $value === (ltrim($value, '0') ?: '0');
    }
}
