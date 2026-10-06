<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Taldres\LastSeen\Trait\LastSeen;

class SoftDeletingUser extends Model
{
    use LastSeen;
    use SoftDeletes;

    protected $table = 'soft_deleting_users';
}
