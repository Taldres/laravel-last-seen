<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserSeenEvent
{
    use Dispatchable, SerializesModels;

    public Model|Authenticatable $user;

    public function __construct(Model|Authenticatable $user)
    {
        $this->user = $user;
    }
}
