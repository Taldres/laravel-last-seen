<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class CastsMethodUser extends Model
{
    use LastSeen;

    protected $table = 'users';

    protected $fillable = ['email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }
}
