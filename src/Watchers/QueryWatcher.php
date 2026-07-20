<?php

namespace Morcen\Probe\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;

class QueryWatcher extends Watcher
{
    /**
     * Per-request map of [fingerprint => ['count' => N, 'ids' => [...]]].
     *
     * @var array<string, array{count: int, ids: int[]}>
     */
    private array $queryMap = [];

    public function register(): void
    {
        app('events')->listen(QueryExecuted::class, function (QueryExecuted $event) {
            $this->onQuery($event);
        });

        // Reset the per-request map after each HTTP request completes.
        app('events')->listen(RequestHandled::class, function () {
            $this->queryMap = [];
        });
    }

    private function onQuery(QueryExecuted $event): void
    {
        $sql         = $this->interpolate($event->sql, $event->bindings);
        $fingerprint = $this->fingerprint($event->sql);
        $durationMs  = round($event->time, 2);
        $slowMs      = (int) config('probe.watchers_config.queries.slow_threshold', 100);
        $n1Threshold = (int) config('probe.watchers_config.queries.n1_threshold', 5);

        $tags = ['query'];

        if ($durationMs >= $slowMs) {
            $tags[] = 'slow';
        }

        $id = $this->record(
            type: 'queries',
            content: [
                'sql'            => $sql,
                'connection'     => $event->connectionName,
                'duration_ms'    => $durationMs,
                'bindings_count' => count($event->bindings),
            ],
            tags: $tags,
            familyHash: $fingerprint,
        );

        if ($id === 0) {
            // Sampling skipped this entry.
            return;
        }

        // Track for N+1 detection.
        if (! isset($this->queryMap[$fingerprint])) {
            $this->queryMap[$fingerprint] = ['count' => 0, 'ids' => []];
        }

        $this->queryMap[$fingerprint]['count']++;
        $this->queryMap[$fingerprint]['ids'][] = $id;

        if ($this->queryMap[$fingerprint]['count'] === $n1Threshold + 1) {
            // Threshold just crossed — back-tag all collected IDs.
            $this->storage->addTagToIds($this->queryMap[$fingerprint]['ids'], 'n1');
        } elseif ($this->queryMap[$fingerprint]['count'] > $n1Threshold + 1) {
            // Already above threshold — tag just this new entry immediately.
            $this->storage->addTagToIds([$id], 'n1');
        }
    }

    /**
     * Replace PDO placeholders with actual binding values for display.
     *
     * @param mixed[] $bindings
     */
    private function interpolate(string $sql, array $bindings): string
    {
        $sensitiveColumns = array_map('strtolower', (array) config('probe.redact.query_bindings', []));
        $columns          = $sensitiveColumns === [] ? [] : $this->columnsForPlaceholders($sql);
        $index            = 0;

        $bindings = array_map(function (mixed $value) use (&$index, $columns, $sensitiveColumns): string {
            $column = $columns[$index] ?? null;
            $index++;

            if ($column !== null && $this->isSensitiveColumn($column, $sensitiveColumns)) {
                return "'[redacted]'";
            }

            if ($value === null) {
                return 'NULL';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_numeric($value)) {
                return (string) $value;
            }

            return "'" . addslashes((string) $value) . "'";
        }, $bindings);

        $sql = preg_replace_callback('/\?/', function () use (&$bindings): string {
            return array_shift($bindings) ?? '?';
        }, $sql);

        return (string) $sql;
    }

    /**
     * Best-effort column name lookup for each `?` placeholder, based on the
     * identifier immediately preceding it (e.g. `password = ?`, `token LIKE ?`).
     * Placeholders that aren't in that shape (e.g. `VALUES (?, ?)`) map to null.
     *
     * @return array<int, string|null>
     */
    private function columnsForPlaceholders(string $sql): array
    {
        preg_match_all('/\?/', $sql, $matches, PREG_OFFSET_CAPTURE);
        $columns = [];

        foreach ($matches[0] as [, $offset]) {
            $before = substr($sql, 0, (int) $offset);

            if (preg_match('/[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\]]?\s*(?:=|!=|<>|<=|>=|<|>|like)\s*$/i', $before, $m)) {
                $columns[] = $m[1];
            } else {
                $columns[] = null;
            }
        }

        return $columns;
    }

    /**
     * @param string[] $sensitiveColumns
     */
    private function isSensitiveColumn(string $column, array $sensitiveColumns): bool
    {
        $column = strtolower($column);

        foreach ($sensitiveColumns as $sensitive) {
            if (str_contains($column, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Produce a stable fingerprint for a query by stripping literal values.
     */
    private function fingerprint(string $sql): string
    {
        // Collapse numeric and string literals to placeholders.
        $normalized = preg_replace([
            "/'\s*([^'\\\\]*(?:\\\\.[^'\\\\]*)*)\s*'/",  // single-quoted strings
            '/\b\d+(\.\d+)?\b/',                          // numbers
        ], ['?', '?'], $sql);

        return md5(strtolower(trim((string) $normalized)));
    }
}
