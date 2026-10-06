<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class NoUpdatedAtUser extends Model
{
    use LastSeen;

    public const UPDATED_AT = null;

    protected $table = 'users';
}
