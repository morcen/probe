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

it('returns 404 instead of a 500 TypeError for a non-numeric entry id', function () {
    app()->instance('probe.auth', fn ($request) => true);

    $response = $this->getJson('/probe/api/entries/abc');

    $response->assertNotFound();
});

it('returns 404 for a missing but numeric entry id', function () {
    app()->instance('probe.auth', fn ($request) => true);

    $response = $this->getJson('/probe/api/entries/999999');

    $response->assertNotFound();
    $response->assertJsonPath('error', 'Entry not found');
});

it('filters entries by exact tag match instead of a raw substring', function () {
    app()->instance('probe.auth', fn ($request) => true);

    DB::table('probe_entries')->insert([
        'type'       => 'requests',
        'content'    => json_encode(['method' => 'GET']),
        'tags'       => 'high',
        'created_at' => now(),
    ]);

    DB::table('probe_entries')->insert([
        'type'       => 'requests',
        'content'    => json_encode(['method' => 'GET']),
        'tags'       => 'highest',
        'created_at' => now(),
    ]);

    $response = $this->getJson('/probe/api/entries?tag=high');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.tags'))->toContain('high');
});

it('matches a tag anywhere in the comma-separated tags list', function () {
    app()->instance('probe.auth', fn ($request) => true);

    DB::table('probe_entries')->insert([
        'type'       => 'jobs',
        'content'    => json_encode(['name' => 'SendEmail']),
        'tags'       => 'default,high,completed',
        'created_at' => now(),
    ]);

    $response = $this->getJson('/probe/api/entries?tag=high');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('excludes a query whose tags merely contain "slow" as a substring from the slow queries report', function () {
    app()->instance('probe.auth', fn ($request) => true);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'select * from users', 'duration_ms' => 5]),
        'tags'        => 'slowdown-candidate',
        'family_hash' => 'hash-not-slow',
        'created_at'  => now(),
    ]);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'select * from posts', 'duration_ms' => 500]),
        'tags'        => 'slow',
        'family_hash' => 'hash-slow',
        'created_at'  => now(),
    ]);

    $response = $this->getJson('/probe/api/queries/slow');

    $response->assertOk();
    $hashes = collect($response->json())->pluck('family_hash');

    expect($hashes)->toContain('hash-slow');
    expect($hashes)->not->toContain('hash-not-slow');
});

it('excludes a query whose tags merely contain "n1" as a substring from the N+1 report', function () {
    app()->instance('probe.auth', fn ($request) => true);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'select * from posts']),
        'tags'        => 'gen1-batch',
        'family_hash' => 'hash-not-n1',
        'created_at'  => now(),
    ]);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'select * from comments']),
        'tags'        => 'n1',
        'family_hash' => 'hash-n1',
        'created_at'  => now(),
    ]);

    $response = $this->getJson('/probe/api/queries/n1');

    $response->assertOk();
    $hashes = collect($response->json())->pluck('family_hash');

    expect($hashes)->toContain('hash-n1');
    expect($hashes)->not->toContain('hash-not-n1');
});

it('pairs the exceptionGroups sample with the row that produced the reported last_seen, not an unrelated min(content) row', function () {
    app()->instance('probe.auth', fn ($request) => true);

    // Lexicographically smaller JSON, but the OLDER occurrence — a plain
    // min(content) would pick this row even though it isn't the latest one.
    DB::table('probe_entries')->insert([
        'type'        => 'exceptions',
        'content'     => json_encode(['class' => 'App\\UserNotFoundException', 'message' => 'User 42 not found']),
        'family_hash' => 'hash-exc',
        'created_at'  => now()->subMinute(),
    ]);

    // Lexicographically larger JSON, and the NEWER occurrence — this is the
    // row max(created_at)/max(id) actually points to.
    DB::table('probe_entries')->insert([
        'type'        => 'exceptions',
        'content'     => json_encode(['class' => 'App\\UserNotFoundException', 'message' => 'User 5 not found']),
        'family_hash' => 'hash-exc',
        'created_at'  => now(),
    ]);

    $response = $this->getJson('/probe/api/exceptions/groups');

    $response->assertOk();
    $group = collect($response->json())->firstWhere('family_hash', 'hash-exc');

    expect($group['message'])->toBe('User 5 not found');
});

it('pairs the slowQueries sample with the row that produced the reported last_seen, not an unrelated min(content) row', function () {
    app()->instance('probe.auth', fn ($request) => true);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'AAA slow query, old occurrence', 'duration_ms' => 500]),
        'tags'        => 'slow',
        'family_hash' => 'hash-slow-report',
        'created_at'  => now()->subMinute(),
    ]);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'ZZZ slow query, new occurrence', 'duration_ms' => 800]),
        'tags'        => 'slow',
        'family_hash' => 'hash-slow-report',
        'created_at'  => now(),
    ]);

    $response = $this->getJson('/probe/api/queries/slow');

    $response->assertOk();
    $group = collect($response->json())->firstWhere('family_hash', 'hash-slow-report');

    expect($group['sql'])->toBe('ZZZ slow query, new occurrence');
});

it('pairs the n1Report sample with the most recent occurrence, not an unrelated min(content) row', function () {
    app()->instance('probe.auth', fn ($request) => true);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'AAA n1 query, old occurrence']),
        'tags'        => 'n1',
        'family_hash' => 'hash-n1-report',
        'created_at'  => now()->subMinute(),
    ]);

    DB::table('probe_entries')->insert([
        'type'        => 'queries',
        'content'     => json_encode(['sql' => 'ZZZ n1 query, new occurrence']),
        'tags'        => 'n1',
        'family_hash' => 'hash-n1-report',
        'created_at'  => now(),
    ]);

    $response = $this->getJson('/probe/api/queries/n1');

    $response->assertOk();
    $group = collect($response->json())->firstWhere('family_hash', 'hash-n1-report');

    expect($group['sql'])->toBe('ZZZ n1 query, new occurrence');
});
