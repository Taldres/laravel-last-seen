<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class PlainDateUser extends Model
{
    use LastSeen;

    protected $table = 'users';

    protected $fillable = ['email'];

    public $timestamps = false;

    protected $casts = ['last_seen_at' => PlainDateCast::class];
}
