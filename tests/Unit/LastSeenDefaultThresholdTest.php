<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Unit;

use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;

it('defaults to the documented thresholds', function () {
    expect(LastSeenDefaultThreshold::Update->value)->toBe(60)
        ->and(LastSeenDefaultThreshold::RecentlySeen->value)->toBe(300);
});
