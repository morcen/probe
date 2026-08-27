<?php

use Illuminate\Support\Facades\DB;

it('truncates all probe entries', function () {
    DB::table('probe_entries')->insert([
        'type'       => 'requests',
        'content'    => json_encode(['method' => 'GET']),
        'created_at' => now(),
    ]);

    expect(DB::table('probe_entries')->count())->toBe(1);

    $this->artisan('probe:clear')
        ->expectsOutput('Probe entries cleared.')
        ->assertSuccessful();

    expect(DB::table('probe_entries')->count())->toBe(0);
});
