<?php

namespace Morcen\Probe\Storage;

interface StorageDriverInterface
{
    /**
     * Persist a single entry to storage and return its ID.
     *
     * @param array{type: string, content: array<mixed>, tags?: string|null, family_hash?: string|null} $entry
     */
    public function store(array $entry): int;

    /**
     * Append a tag to a set of entries by their IDs.
     *
     * @param int[] $ids
     */
    public function addTagToIds(array $ids, string $tag): void;

    /**
     * Delete entries older than the configured TTL for each type.
     */
    public function prune(): void;

    /**
     * Delete all entries from storage.
     */
    public function clear(): void;
}
