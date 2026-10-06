<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class UlidUser extends Model
{
    use HasUlids;
    use LastSeen;

    protected $table = 'ulid_users';
}
