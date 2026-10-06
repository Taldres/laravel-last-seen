<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class SecondaryConnectionUser extends Model
{
    use LastSeen;

    protected $connection = 'secondary';

    protected $table = 'users';

    protected $fillable = ['email'];
}
