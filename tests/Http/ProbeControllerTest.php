<?php

use Illuminate\Support\Facades\DB;

it('renders the dashboard without loading Tailwind or Alpine.js from a third-party CDN', function () {
    app()->instance('probe.auth', fn ($request) => true);

    $response = $this->get('/probe');

    $response->assertOk();
    $response->assertDontSee('cdn.tailwindcss.com', false);
    $response->assertDontSee('cdn.jsdelivr.net', false);
});

it('clamps per_page=0 to a minimum instead of throwing DivisionByZeroError', function () {
    app()->instance('probe.auth', fn ($request) => true);

    $response = $this->getJson('/probe/api/entries?per_page=0');

    $response->assertOk();
    $response->assertJsonPath('per_page', 1);
});

it('clamps a negative per_page instead of dumping the entire table unpaginated', function () {
    app()->instance('probe.auth', fn ($request) => true);

    for ($i = 0; $i < 5; $i++) {
        DB::table('probe_entries')->insert([
            'type'       => 'requests',
            'content'    => json_encode(['index' => $i]),
            'created_at' => now(),
        ]);
    }

    $response = $this->getJson('/probe/api/entries?per_page=-5');

    $response->assertOk();
    $response->assertJsonPath('per_page', 1);
    expect($response->json('data'))->toHaveCount(1);
});
