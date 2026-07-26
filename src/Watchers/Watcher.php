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
            try {
                $id = $this->storage->store([
                    'type'        => $type,
                    'content'     => $content,
                    'tags'        => implode(',', array_filter(array_unique($tags))),
                    'family_hash' => $familyHash,
                ]);
            } catch (\Throwable) {
                // Storage failures must never crash the host application.
                return 0;
            }

            $this->alert($type, $content, $tags);
        } finally {
            self::$recording = false;
        }

        return $id;
    }

    /**
     * Run watcher work that isn't already covered by record()'s own
     * try/catch (content construction, follow-up storage writes) so that
     * an unexpected value or a storage failure there can never crash the
     * host request/job/task either.
     */
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Recording must never crash the host application.
        }
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

    /**
     * Replace values whose key matches one of the given sensitive field
     * substrings with '[redacted]', recursing into nested arrays.
     *
     * @param array<array-key, mixed> $data
     * @param string[] $fields
     * @return array<array-key, mixed>
     */
    protected function redactFields(array $data, array $fields): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveField($key, $fields)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactFields($value, $fields);
            }
        }

        return $data;
    }

    /**
     * @param string[] $fields
     */
    protected function isSensitiveField(string $key, array $fields): bool
    {
        $key = strtolower($key);

        foreach ($fields as $field) {
            if (str_contains($key, strtolower($field))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort redaction for free-form text (exception messages, command
     * output, etc.) that doesn't have the structured key/value shape
     * redactFields() expects. Scans for `key: value`, `key=value` and
     * `key => value` shaped substrings whose key matches one of the given
     * sensitive field substrings and replaces the value with '[redacted]'.
     * Text that doesn't match that shape (e.g. a plain sentence) is left
     * untouched — this is a defense-in-depth pass, not a guarantee.
     *
     * @param string[] $fields
     */
    protected function redactMessage(string $message, array $fields): string
    {
        if (empty($fields) || $message === '') {
            return $message;
        }

        $pattern = '/([A-Za-z0-9_-]+)(\s*(?:=>|[:=])\s*)("[^"]*"|\'[^\']*\'|[^\s,;]+)/';

        $redacted = preg_replace_callback($pattern, function (array $matches) use ($fields) {
            [, $key, $separator, $value] = $matches;

            if (! $this->isSensitiveField($key, $fields)) {
                return $key . $separator . $value;
            }

            $quote = ($value !== '' && in_array($value[0], ['"', "'"], true)) ? $value[0] : '';

            return $key . $separator . $quote . '[redacted]' . $quote;
        }, $message);

        return $redacted ?? $message;
    }
}
