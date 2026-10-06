<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class CustomKeyMember extends Model
{
    use LastSeen;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'members';

    protected $primaryKey = 'member_no';
}
