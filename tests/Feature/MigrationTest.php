<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestModels\User;

beforeEach(function () {
    config(['last-seen.models.user' => User::class]);

    $this->migration = require __DIR__.'/../../database/migrations/add_last_seen_at_to_users_table.php.stub';
});

it('adds and drops the last_seen_at column on the configured user table', function () {
    $this->migration->down();
    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeFalse();

    $this->migration->up();
    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue();
});
