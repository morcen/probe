<?php

use Illuminate\Support\Facades\DB;
use Morcen\Probe\Storage\DatabaseDriver;

it('stores an entry with valid content as-is', function () {
    $driver = new DatabaseDriver();

    $id = $driver->store([
        'type'    => 'requests',
        'content' => ['method' => 'GET', 'uri' => '/users'],
        'tags'    => 'request,get',
    ]);

    $row     = DB::table('probe_entries')->find($id);
    $content = json_decode($row->content, true);

    expect($content)->toBe(['method' => 'GET', 'uri' => '/users']);
});

it('falls back to a placeholder instead of corrupting the entry when content fails to json_encode', function () {
    $driver = new DatabaseDriver();

    // Invalid UTF-8 bytes (e.g. a binary response body) make json_encode() return false.
    $id = $driver->store([
        'type'    => 'requests',
        'content' => ['payload' => "\xFF\xD8\xFF\xE0binarydata\x00\x01\x02"],
        'tags'    => 'request,post',
    ]);

    $row     = DB::table('probe_entries')->find($id);
    $content = json_decode($row->content, true);

    expect($content)->toBeArray()
        ->and($content)->toBe(['error' => 'probe_failed_to_encode_entry_content']);
});

it('does nothing when addTagToIds is called with an empty id list', function () {
    $driver = new DatabaseDriver();

    DB::enableQueryLog();

    $driver->addTagToIds([], 'n1');

    expect(DB::getQueryLog())->toBeEmpty();
});

it('appends a tag to each id in a single batched update', function () {
    $driver = new DatabaseDriver();

    $ids = collect(range(1, 5))->map(fn () => $driver->store([
        'type'    => 'queries',
        'content' => ['sql' => 'select * from posts'],
        'tags'    => 'query',
    ]))->all();

    DB::enableQueryLog();

    $driver->addTagToIds($ids, 'n1');

    // One SELECT to fetch existing tags, one UPDATE to write them all back —
    // regardless of how many ids are being tagged.
    expect(DB::getQueryLog())->toHaveCount(2);

    foreach ($ids as $id) {
        $tags = explode(',', DB::table('probe_entries')->find($id)->tags);
        expect($tags)->toContain('query')->toContain('n1');
    }
});

it('does not duplicate a tag that an id already has', function () {
    $driver = new DatabaseDriver();

    $id = $driver->store([
        'type'    => 'queries',
        'content' => ['sql' => 'select * from posts'],
        'tags'    => 'query,n1',
    ]);

    $driver->addTagToIds([$id], 'n1');

    $tags = explode(',', DB::table('probe_entries')->find($id)->tags);

    expect(array_count_values($tags)['n1'])->toBe(1);
});

it('only updates ids that are missing the tag, leaving already-tagged rows untouched', function () {
    $driver = new DatabaseDriver();

    $alreadyTagged = $driver->store([
        'type'    => 'queries',
        'content' => ['sql' => 'select * from posts'],
        'tags'    => 'query,n1',
    ]);

    $needsTagging = $driver->store([
        'type'    => 'queries',
        'content' => ['sql' => 'select * from posts'],
        'tags'    => 'query',
    ]);

    $driver->addTagToIds([$alreadyTagged, $needsTagging], 'n1');

    expect(DB::table('probe_entries')->find($alreadyTagged)->tags)->toBe('query,n1')
        ->and(explode(',', DB::table('probe_entries')->find($needsTagging)->tags))->toContain('n1');
});

it('adds a tag to an entry that has no existing tags', function () {
    $driver = new DatabaseDriver();

    $id = $driver->store([
        'type'    => 'queries',
        'content' => ['sql' => 'select * from posts'],
    ]);

    $driver->addTagToIds([$id], 'n1');

    expect(DB::table('probe_entries')->find($id)->tags)->toBe('n1');
});

it('prunes entries older than the configured TTL for a given type', function () {
    config()->set('probe.pruning', ['requests' => 7]);

    $driver = new DatabaseDriver();

    $oldId = DB::table('probe_entries')->insertGetId([
        'type'       => 'requests',
        'content'    => json_encode(['method' => 'GET']),
        'created_at' => now()->subDays(10),
    ]);

    $recentId = DB::table('probe_entries')->insertGetId([
        'type'       => 'requests',
        'content'    => json_encode(['method' => 'GET']),
        'created_at' => now()->subDays(1),
    ]);

    $driver->prune();

    expect(DB::table('probe_entries')->find($oldId))->toBeNull();
    expect(DB::table('probe_entries')->find($recentId))->not->toBeNull();
});

it('does not prune a type whose TTL is configured as null', function () {
    config()->set('probe.pruning', ['exceptions' => null]);

    $driver = new DatabaseDriver();

    $id = DB::table('probe_entries')->insertGetId([
        'type'       => 'exceptions',
        'content'    => json_encode(['class' => 'RuntimeException']),
        'created_at' => now()->subYears(5),
    ]);

    $driver->prune();

    expect(DB::table('probe_entries')->find($id))->not->toBeNull();
});

it('only prunes entries of the configured type, leaving other types untouched', function () {
    config()->set('probe.pruning', ['requests' => 7]);

    $driver = new DatabaseDriver();

    $oldRequest = DB::table('probe_entries')->insertGetId([
        'type'       => 'requests',
        'content'    => json_encode(['method' => 'GET']),
        'created_at' => now()->subDays(10),
    ]);

    $oldException = DB::table('probe_entries')->insertGetId([
        'type'       => 'exceptions',
        'content'    => json_encode(['class' => 'RuntimeException']),
        'created_at' => now()->subDays(10),
    ]);

    $driver->prune();

    expect(DB::table('probe_entries')->find($oldRequest))->toBeNull();
    expect(DB::table('probe_entries')->find($oldException))->not->toBeNull();
});

it('clears every entry from storage regardless of type', function () {
    $driver = new DatabaseDriver();

    $driver->store(['type' => 'requests', 'content' => ['method' => 'GET']]);
    $driver->store(['type' => 'exceptions', 'content' => ['class' => 'RuntimeException']]);

    expect(DB::table('probe_entries')->count())->toBe(2);

    $driver->clear();

    expect(DB::table('probe_entries')->count())->toBe(0);
});
