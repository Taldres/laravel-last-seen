<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class FormattedLastSeenUser extends Model
{
    use LastSeen;

    protected $table = 'users';

    protected $fillable = ['email'];

    /**
     * @return Attribute<non-falsy-string|null, never>
     */
    protected function lastSeenAt(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?string => is_string($value) ? "seen at {$value}" : null);
    }
}
