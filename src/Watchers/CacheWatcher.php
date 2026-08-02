<?php

namespace Morcen\Probe\Watchers;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;

class CacheWatcher extends Watcher
{
    public function register(): void
    {
        app('events')->listen(CacheHit::class, fn (CacheHit $e) => $this->record(
            type: 'cache',
            content: ['event' => 'hit', 'key' => $this->redactKey($e->key), 'store' => $e->storeName ?? 'default'],
            tags: ['cache', 'hit'],
        ));

        app('events')->listen(CacheMissed::class, fn (CacheMissed $e) => $this->record(
            type: 'cache',
            content: ['event' => 'miss', 'key' => $this->redactKey($e->key), 'store' => $e->storeName ?? 'default'],
            tags: ['cache', 'miss'],
        ));

        app('events')->listen(KeyWritten::class, fn (KeyWritten $e) => $this->record(
            type: 'cache',
            content: [
                'event'   => 'write',
                'key'     => $this->redactKey($e->key),
                'store'   => $e->storeName ?? 'default',
                'seconds' => $e->seconds,
            ],
            tags: ['cache', 'write'],
        ));

        app('events')->listen(KeyForgotten::class, fn (KeyForgotten $e) => $this->record(
            type: 'cache',
            content: ['event' => 'forget', 'key' => $this->redactKey($e->key), 'store' => $e->storeName ?? 'default'],
            tags: ['cache', 'forget'],
        ));
    }

    /**
     * Redact the entire cache key when it contains one of the configured
     * sensitive substrings. Cache keys have no separate field name to check
     * against (unlike request bodies or query bindings), so a match redacts
     * the whole key rather than just part of it.
     */
    private function redactKey(string $key): string
    {
        $fields = config('probe.redact.cache_keys', []);

        return $this->isSensitiveField($key, $fields) ? '[redacted]' : $key;
    }
}
