<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Trait\LastSeen;

class IsoFormatUser extends Model
{
    use LastSeen;

    protected $table = 'users';

    protected $dateFormat = 'Y-m-d\TH:i:sP';
}
