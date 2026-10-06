<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class StringKeyUser extends Model
{
    use LastSeen;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'string_key_users';

    protected $primaryKey = 'handle';

    protected $keyType = 'string';
}
