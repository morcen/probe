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

        $tags = $entry['tags'] ?? null;

        $id = (int) DB::table('probe_entries')->insertGetId([
            'type'        => $entry['type'],
            'content'     => $content,
            'tags'        => $tags,
            'family_hash' => $entry['family_hash'] ?? null,
            'created_at'  => Carbon::now(),
        ]);

        $this->syncTagRows($id, $tags ? explode(',', $tags) : []);

        return $id;
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

        DB::table('probe_entry_tags')->insertOrIgnore(
            array_map(fn (int $id) => ['entry_id' => $id, 'tag' => $tag], $idsToTag)
        );
    }

    /**
     * Keep probe_entry_tags — the indexed lookup table whereHasTag() queries
     * against — in sync with the tags a row was just given. insertOrIgnore()
     * makes this safe to call with a tag a row already has.
     *
     * @param string[] $tags
     */
    private function syncTagRows(int $entryId, array $tags): void
    {
        $tags = array_values(array_filter(array_unique($tags)));

        if (empty($tags)) {
            return;
        }

        DB::table('probe_entry_tags')->insertOrIgnore(
            array_map(fn (string $tag) => ['entry_id' => $entryId, 'tag' => $tag], $tags)
        );
    }

    public function prune(): void
    {
        $pruning = config('probe.pruning', []);

        foreach ($pruning as $type => $days) {
            if ($days === null) {
                continue;
            }

            $cutoff = Carbon::now()->subDays((int) $days);

            // probe_entry_tags.entry_id has no foreign-key-independent TTL of
            // its own, so its rows for a pruned entry must be removed here
            // rather than relying on cascadeOnDelete() alone — not every
            // database/driver combination enforces FK constraints.
            DB::table('probe_entry_tags')->whereIn('entry_id', function ($query) use ($type, $cutoff) {
                $query->select('id')
                    ->from('probe_entries')
                    ->where('type', $type)
                    ->where('created_at', '<', $cutoff);
            })->delete();

            DB::table('probe_entries')
                ->where('type', $type)
                ->where('created_at', '<', $cutoff)
                ->delete();
        }
    }

    public function clear(): void
    {
        DB::table('probe_entry_tags')->truncate();
        DB::table('probe_entries')->truncate();
    }
}
