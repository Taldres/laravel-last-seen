<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

abstract class ParentTrackedUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use LastSeen;

    protected $fillable = ['email'];

    public $timestamps = false;

    protected $table = 'users';
}
