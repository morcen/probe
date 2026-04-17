<?php

namespace Morcen\Probe\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DatabaseDriver implements StorageDriverInterface
{
    public function store(array $entry): int
    {
        return (int) DB::table('probe_entries')->insertGetId([
            'type'        => $entry['type'],
            'content'     => json_encode($entry['content']),
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

        foreach ($rows as $row) {
            $existing = $row->tags ? explode(',', $row->tags) : [];

            if (in_array($tag, $existing, true)) {
                continue;
            }

            $existing[] = $tag;

            DB::table('probe_entries')
                ->where('id', $row->id)
                ->update(['tags' => implode(',', $existing)]);
        }
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
