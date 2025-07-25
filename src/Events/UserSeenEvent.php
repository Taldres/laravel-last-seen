<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserSeenEvent
{
    use Dispatchable, SerializesModels;

    public Model $user;

    public function __construct(Model $user)
    {
        $this->user = $user;
    }
}
