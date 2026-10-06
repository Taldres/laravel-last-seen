<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class MicrosecondUser extends Model
{
    use LastSeen;

    protected $table = 'users';

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
