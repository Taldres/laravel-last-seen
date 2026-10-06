<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class CustomTimestampColumnsUser extends Model
{
    use LastSeen;

    public const CREATED_AT = 'registered_at';

    public const UPDATED_AT = 'modified_at';

    protected $table = 'custom_timestamp_users';
}
