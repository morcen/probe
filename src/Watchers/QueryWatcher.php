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

    /**
     * Fallback cap on tracked fingerprints, used when nothing ever resets
     * the map (queue workers and long-running console commands never fire
     * RequestHandled). Oldest fingerprints are evicted once this is hit,
     * so memory stays bounded even outside the HTTP request lifecycle.
     */
    private const DEFAULT_MAX_TRACKED_FINGERPRINTS = 1000;

    /**
     * Cap on the stored, fully-interpolated SQL string. Bound values can be
     * arbitrarily large (a JSON document, a base64-encoded file, a long text
     * column), and unlike every other watcher's payload, this one had no
     * limit — a single query could otherwise bloat the entries table and
     * slow the dashboard down loading it back.
     */
    private const MAX_SQL_BYTES = 65536; // 64 KB

    public function register(): void
    {
        app('events')->listen(QueryExecuted::class, function (QueryExecuted $event) {
            $this->onQuery($event);
        });

        // Reset the per-request map after each HTTP request completes.
        app('events')->listen(RequestHandled::class, function () {
            $this->resetPerRequestState();
        });
    }

    public function resetPerRequestState(): void
    {
        $this->queryMap = [];
    }

    private function onQuery(QueryExecuted $event): void
    {
        $this->safely(function () use ($event) {
            [$sql, $sqlTruncated] = $this->truncateSql($this->interpolate($event->sql, $event->bindings));
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
                    'sql_truncated'  => $sqlTruncated,
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
                $maxTracked = (int) config(
                    'probe.watchers_config.queries.max_tracked_fingerprints',
                    self::DEFAULT_MAX_TRACKED_FINGERPRINTS
                );

                if ($maxTracked > 0 && count($this->queryMap) >= $maxTracked) {
                    // Evict the oldest tracked fingerprint (PHP arrays preserve
                    // insertion order) to keep memory bounded in processes that
                    // never fire RequestHandled.
                    array_shift($this->queryMap);
                }

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
        });
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

            if (is_object($value) && ! method_exists($value, '__toString')) {
                return "'[unrepresentable]'";
            }

            return "'" . addslashes((string) $value) . "'";
        }, $bindings);

        $sql = preg_replace_callback('/\?/', function () use (&$bindings): string {
            return array_shift($bindings) ?? '?';
        }, $sql);

        return (string) $sql;
    }

    /**
     * Cap the fully-interpolated SQL string to MAX_SQL_BYTES, mirroring the
     * truncation every other watcher already applies to its payload.
     *
     * @return array{string, bool}
     */
    private function truncateSql(string $sql): array
    {
        if (strlen($sql) > self::MAX_SQL_BYTES) {
            return [substr($sql, 0, self::MAX_SQL_BYTES), true];
        }

        return [$sql, false];
    }

    /**
     * Best-effort column name lookup for each `?` placeholder. Handles two shapes:
     * an identifier immediately preceding it (e.g. `password = ?`, `token LIKE ?`),
     * and positional placeholders inside `INSERT ... (col1, col2) VALUES (?, ?)`
     * row groups, mapped onto the column list by position. Placeholders that
     * aren't in either shape map to null.
     *
     * @return array<int, string|null>
     */
    private function columnsForPlaceholders(string $sql): array
    {
        preg_match_all('/\?/', $sql, $matches, PREG_OFFSET_CAPTURE);
        $columns = [];

        $insertColumns = $this->insertColumns($sql);
        $valuesRange   = $insertColumns !== null ? $this->valuesGroupsRange($sql) : null;
        $insertIndex   = 0;
        $insertCount   = $insertColumns !== null ? count($insertColumns) : 0;

        $clauseColumns = $this->clauseColumnsForPlaceholders($sql);

        foreach ($matches[0] as [, $offset]) {
            $offset = (int) $offset;

            if ($valuesRange !== null && $offset >= $valuesRange[0] && $offset < $valuesRange[1]) {
                $columns[] = $insertColumns[$insertIndex % $insertCount];
                $insertIndex++;
                continue;
            }

            if (isset($clauseColumns[$offset])) {
                $columns[] = $clauseColumns[$offset];
                continue;
            }

            $before = substr($sql, 0, $offset);

            if (preg_match('/[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\]]?\s*(?:=|!=|<>|<=|>=|<|>|like)\s*$/i', $before, $m)) {
                $columns[] = $m[1];
            } else {
                $columns[] = null;
            }
        }

        return $columns;
    }

    /**
     * Best-effort column lookup for placeholders inside `IN (...)` / `NOT IN (...)`
     * and `BETWEEN ? AND ?` clauses, which the identifier-adjacency regex in
     * columnsForPlaceholders() can't reach since the placeholder isn't immediately
     * preceded by the column name. Keyed by the placeholder's byte offset in $sql.
     *
     * @return array<int, string>
     */
    private function clauseColumnsForPlaceholders(string $sql): array
    {
        $columns = [];

        if (preg_match_all('/[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\]]?\s+(?:not\s+)?in\s*\(([^()]*)\)/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => [$column]) {
                [$groupText, $groupOffset] = $m[2][$i];
                $pos = 0;

                while (($p = strpos($groupText, '?', $pos)) !== false) {
                    $columns[(int) $groupOffset + $p] = $column;
                    $pos = $p + 1;
                }
            }
        }

        if (preg_match_all('/[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\]]?\s+(?:not\s+)?between\s+(\?)\s+and\s+(\?)/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => [$column]) {
                $columns[(int) $m[2][$i][1]] = $column;
                $columns[(int) $m[3][$i][1]] = $column;
            }
        }

        return $columns;
    }

    /**
     * Extract the column list from an `INSERT INTO table (col1, col2, ...) VALUES` statement.
     *
     * @return string[]|null
     */
    private function insertColumns(string $sql): ?array
    {
        if (! preg_match('/^\s*(?:insert\s+ignore\s+into|replace\s+into|insert\s+into)\s+[`"\[]?[a-zA-Z_][a-zA-Z0-9_.]*[`"\]]?\s*\(([^)]+)\)\s*values/i', $sql, $m)) {
            return null;
        }

        $columns = array_map(
            static fn (string $column): string => trim($column, " \t\n\r\0\x0B`\"[]"),
            explode(',', $m[1])
        );

        return $columns === [] ? null : $columns;
    }

    /**
     * Byte offset range covering the `VALUES (...), (...), ...` row groups, so
     * placeholders inside them can be mapped positionally onto the column list,
     * while placeholders elsewhere (e.g. an `ON DUPLICATE KEY UPDATE col = ?`
     * clause) still fall back to the identifier-based lookup.
     *
     * @return array{0: int, 1: int}|null
     */
    private function valuesGroupsRange(string $sql): ?array
    {
        if (! preg_match('/\bvalues\s*((?:\([^()]*\)\s*,?\s*)+)/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = (int) $m[1][1];

        return [$start, $start + strlen($m[1][0])];
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
