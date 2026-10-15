<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserWasActiveEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Model|Authenticatable $user) {}
}
