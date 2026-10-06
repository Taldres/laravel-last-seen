<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class UuidUser extends Model
{
    use HasUuids;
    use LastSeen;

    protected $table = 'uuid_users';
}
