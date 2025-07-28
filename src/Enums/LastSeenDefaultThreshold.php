<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Enums;

enum LastSeenDefaultThreshold: int
{
    case Update = 60;
    case RecentlySeen = 300;
}
