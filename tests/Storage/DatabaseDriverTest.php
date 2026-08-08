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
