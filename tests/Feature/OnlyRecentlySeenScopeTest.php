<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\LastSeen\Tests\TestModels\User;

it('qualifies last_seen_at so a joined table with the same column does not clash', function () {
    Schema::create('devices', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->timestamp('last_seen_at')->nullable();
    });

    $recent = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()]);
    $stale = User::forceCreate(['email' => fake()->email(), 'last_seen_at' => now()->subDay()]);

    DB::table('devices')->insert([
        ['user_id' => $recent->id, 'last_seen_at' => now()->subDay()],
        ['user_id' => $stale->id, 'last_seen_at' => now()],
    ]);

    $ids = User::query()
        ->select('users.*')
        ->join('devices', 'devices.user_id', '=', 'users.id')
        ->onlyRecentlySeen()
        ->pluck('users.id')
        ->all();

    expect($ids)->toBe([$recent->id]);
});
