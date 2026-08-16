<?php

namespace Morcen\Probe\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DatabaseDriver implements StorageDriverInterface
{
    public function store(array $entry): int
    {
        $content = json_encode($entry['content']);

        if ($content === false) {
            $content = json_encode(['error' => 'probe_failed_to_encode_entry_content']);
        }

        return (int) DB::table('probe_entries')->insertGetId([
            'type'        => $entry['type'],
            'content'     => $content,
            'tags'        => $entry['tags'] ?? null,
            'family_hash' => $entry['family_hash'] ?? null,
            'created_at'  => Carbon::now(),
        ]);
    }

    public function addTagToIds(array $ids, string $tag): void
    {
        if (empty($ids)) {
            return;
        }

        $rows = DB::table('probe_entries')
            ->whereIn('id', $ids)
            ->get(['id', 'tags']);

        $cases    = [];
        $bindings = [];
        $idsToTag = [];

        foreach ($rows as $row) {
            $existing = $row->tags ? explode(',', $row->tags) : [];

            if (in_array($tag, $existing, true)) {
                continue;
            }

            $existing[] = $tag;

            $cases[]    = 'WHEN ? THEN ?';
            $bindings[] = $row->id;
            $bindings[] = implode(',', $existing);
            $idsToTag[] = $row->id;
        }

        if (empty($idsToTag)) {
            return;
        }

        // A single CASE-based UPDATE instead of one query per row, so back-tagging
        // an N+1 batch costs one query regardless of how many ids need tagging.
        $table        = DB::getTablePrefix() . 'probe_entries';
        $placeholders = implode(',', array_fill(0, count($idsToTag), '?'));

        DB::update(
            "UPDATE {$table} SET tags = CASE id " . implode(' ', $cases) . ' END WHERE id IN (' . $placeholders . ')',
            array_merge($bindings, $idsToTag)
        );
    }

    public function prune(): void
    {
        $pruning = config('probe.pruning', []);

        foreach ($pruning as $type => $days) {
            if ($days === null) {
                continue;
            }

            DB::table('probe_entries')
                ->where('type', $type)
                ->where('created_at', '<', Carbon::now()->subDays((int) $days))
                ->delete();
        }
    }

    public function clear(): void
    {
        DB::table('probe_entries')->truncate();
    }
}
