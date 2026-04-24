<?php

namespace Morcen\Probe\Watchers;

use Morcen\Probe\Alerts\AlertDispatcher;
use Morcen\Probe\Storage\StorageDriverInterface;

abstract class Watcher
{
    public function __construct(protected StorageDriverInterface $storage)
    {
    }

    /**
     * Register this watcher's event listeners.
     */
    abstract public function register(): void;

    private static bool $recording = false;

    /**
     * Persist an entry, respecting the configured sampling rate.
     * Also dispatches alerts if any configured rules match.
     *
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    protected function record(
        string $type,
        array $content,
        array $tags = [],
        ?string $familyHash = null
    ): int {
        if (self::$recording) {
            return 0;
        }

        $rate = (float) config('probe.sampling_rate', 1.0);

        if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) > $rate) {
            return 0;
        }

        self::$recording = true;

        try {
            $id = $this->storage->store([
                'type'        => $type,
                'content'     => $content,
                'tags'        => implode(',', array_filter(array_unique($tags))),
                'family_hash' => $familyHash,
            ]);

            $this->alert($type, $content, $tags);
        } finally {
            self::$recording = false;
        }

        return $id;
    }

    /**
     * Dispatch alerts for this entry if rules are configured.
     *
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    private function alert(string $type, array $content, array $tags): void
    {
        if (empty(config('probe.alerts', []))) {
            return;
        }

        try {
            app(AlertDispatcher::class)->dispatch($type, $content, $tags);
        } catch (\Throwable) {
            // Alerts must never crash the application.
        }
    }

    /**
     * Check whether the given URI should be ignored.
     */
    protected function isIgnoredPath(string $uri): bool
    {
        $ignored = config('probe.watchers_config.requests.ignore_paths', []);

        foreach ($ignored as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }
}
