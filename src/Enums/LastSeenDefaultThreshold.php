<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Enums;

enum LastSeenDefaultThreshold: int
{
    case Update = 60;
    case RecentlySeen = 300;

    /**
     * Turns a value from the .env file into the threshold the configuration holds. A string of digits becomes an
     * integer and a missing or empty value becomes the default. Any other value is passed on unchanged, so the
     * package rejects it with an exception instead of a cast turning it into 0.
     */
    public function fromEnv(mixed $value): mixed
    {
        return match (true) {
            $value === null, $value === '' => $this->value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => $value,
        };
    }
}
