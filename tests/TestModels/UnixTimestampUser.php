<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class UnixTimestampUser extends Model
{
    use LastSeen;

    protected $table = 'unix_timestamp_users';

    protected $fillable = ['email'];

    protected $dateFormat = 'U';
}
