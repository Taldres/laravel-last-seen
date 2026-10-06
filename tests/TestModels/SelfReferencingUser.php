<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Taldres\LastSeen\Trait\LastSeen;

class SelfReferencingUser extends Model
{
    use LastSeen;

    protected $table = 'users';

    protected $fillable = ['email', 'referrer_id'];

    /**
     * @return BelongsTo<self, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }
}
