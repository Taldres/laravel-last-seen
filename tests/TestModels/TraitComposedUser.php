<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;

class TraitComposedUser extends Model
{
    use ComposedLastSeen;

    protected $table = 'users';

    protected $fillable = ['email'];
}
