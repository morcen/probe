<?php

namespace Morcen\Probe\Http;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ProbeController extends Controller
{
    /**
     * Serve the Probe SPA shell.
     */
    public function index(): Response
    {
        return response()->view('probe::app');
    }

    /**
     * Paginated list of entries, optionally filtered by type and tag.
     */
    public function entries(Request $request): JsonResponse
    {
        $type    = $request->query('type');
        $tag     = $request->query('tag');
        $search  = $request->query('search');
        $perPage = max(1, min((int) $request->query('per_page', 50), 200));

        $query = DB::table('probe_entries')
            ->orderByDesc('id');

        if ($type) {
            $query->where('type', $type);
        }

        if ($tag) {
            $this->whereHasTag($query, $tag);
        }

        if ($search) {
            $query->where('content', 'LIKE', "%{$search}%");
        }

        $entries = $query->paginate($perPage);

        $entries->getCollection()->transform(function (object $row): array {
            return [
                'id'          => $row->id,
                'type'        => $row->type,
                'tags'        => $row->tags ? explode(',', $row->tags) : [],
                'family_hash' => $row->family_hash,
                'created_at'  => $row->created_at,
                'summary'     => $this->summarize($row),
            ];
        });

        return response()->json($entries);
    }

    /**
     * Full detail for a single entry.
     */
    public function show(int $id): JsonResponse
    {
        $row = DB::table('probe_entries')->find($id);

        if (! $row) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        return response()->json([
            'id'          => $row->id,
            'type'        => $row->type,
            'tags'        => $row->tags ? explode(',', $row->tags) : [],
            'family_hash' => $row->family_hash,
            'created_at'  => $row->created_at,
            'content'     => json_decode($row->content, true),
        ]);
    }

    /**
     * Counts per type for the sidebar badges.
     */
    public function stats(): JsonResponse
    {
        $counts = DB::table('probe_entries')
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return response()->json($counts);
    }

    /**
     * Group exceptions by family_hash and return deduped counts.
     */
    public function exceptionGroups(): JsonResponse
    {
        $groups = DB::table('probe_entries')
            ->where('type', 'exceptions')
            ->whereNotNull('family_hash')
            ->selectRaw('family_hash, count(*) as occurrences, max(created_at) as last_seen, max(id) as latest_id')
            ->groupBy('family_hash')
            ->orderByDesc('occurrences')
            ->limit(100)
            ->get();

        $samples = $this->latestSamples($groups->pluck('latest_id'));

        $groups = $groups->map(function (object $row) use ($samples): array {
            $content = json_decode($samples[$row->latest_id] ?? null, true) ?? [];
            return [
                'family_hash'  => $row->family_hash,
                'occurrences'  => $row->occurrences,
                'last_seen'    => $row->last_seen,
                'class'        => $content['class'] ?? 'Unknown',
                'message'      => $content['message'] ?? '',
                'file'         => $content['file'] ?? '',
                'line'         => $content['line'] ?? 0,
            ];
        });

        return response()->json($groups);
    }

    /**
     * Top slow queries grouped by fingerprint.
     */
    public function slowQueries(): JsonResponse
    {
        $query = DB::table('probe_entries')
            ->where('type', 'queries');
        $this->whereHasTag($query, 'slow');

        $rows = $query
            ->selectRaw('family_hash, count(*) as occurrences, max(created_at) as last_seen, max(id) as latest_id')
            ->groupBy('family_hash')
            ->orderByDesc('occurrences')
            ->limit(50)
            ->get();

        $samples = $this->latestSamples($rows->pluck('latest_id'));

        $rows = $rows->map(function (object $row) use ($samples): array {
            $content = json_decode($samples[$row->latest_id] ?? null, true) ?? [];
            return [
                'family_hash'  => $row->family_hash,
                'occurrences'  => $row->occurrences,
                'last_seen'    => $row->last_seen,
                'sql'          => $content['sql'] ?? '',
                'duration_ms'  => $content['duration_ms'] ?? null,
                'connection'   => $content['connection'] ?? 'default',
            ];
        });

        return response()->json($rows);
    }

    /**
     * N+1 query report.
     */
    public function n1Report(): JsonResponse
    {
        $query = DB::table('probe_entries')
            ->where('type', 'queries');
        $this->whereHasTag($query, 'n1');

        $rows = $query
            ->selectRaw('family_hash, count(*) as total_executions, max(id) as latest_id')
            ->groupBy('family_hash')
            ->orderByDesc('total_executions')
            ->limit(50)
            ->get();

        $samples = $this->latestSamples($rows->pluck('latest_id'));

        $rows = $rows->map(function (object $row) use ($samples): array {
            $content = json_decode($samples[$row->latest_id] ?? null, true) ?? [];
            return [
                'family_hash'       => $row->family_hash,
                'total_executions'  => $row->total_executions,
                'sql'               => $content['sql'] ?? '',
                'connection'        => $content['connection'] ?? 'default',
            ];
        });

        return response()->json($rows);
    }

    /**
     * Export entries as JSON.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $type = $request->query('type');

        return response()->streamDownload(function () use ($type) {
            $query = DB::table('probe_entries')->orderByDesc('id');

            if ($type) {
                $query->where('type', $type);
            }

            echo '[';
            $first = true;

            $query->chunk(500, function ($rows) use (&$first) {
                foreach ($rows as $row) {
                    if (! $first) {
                        echo ',';
                    }
                    echo json_encode([
                        'id'          => $row->id,
                        'type'        => $row->type,
                        'tags'        => $row->tags,
                        'family_hash' => $row->family_hash,
                        'content'     => json_decode($row->content),
                        'created_at'  => $row->created_at,
                    ]);
                    $first = false;
                }
            });

            echo ']';
        }, 'probe-export-' . date('Y-m-d') . '.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Server-Sent Events stream for real-time updates.
     */
    public function stream(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // A reconnecting EventSource sends the last event id it saw back via the
        // Last-Event-ID header per the SSE spec, so honor that ahead of the
        // last_id query param (which the bundled dashboard client never sets)
        // to avoid replaying the entire probe_entries history on every reconnect.
        $lastId = (int) ($request->header('Last-Event-ID') ?? $request->query('last_id', 0));

        return response()->stream(function () use ($lastId) {
            $current  = $lastId;
            $deadline = time() + 60;

            while (time() < $deadline) {
                if (connection_aborted()) {
                    break;
                }

                $rows = DB::table('probe_entries')
                    ->where('id', '>', $current)
                    ->orderBy('id')
                    ->limit(20)
                    ->get(['id', 'type', 'tags', 'created_at']);

                foreach ($rows as $row) {
                    $current = $row->id;

                    $data = json_encode([
                        'id'         => $row->id,
                        'type'       => $row->type,
                        'tags'       => $row->tags ? explode(',', $row->tags) : [],
                        'created_at' => $row->created_at,
                    ]);

                    echo "id: {$row->id}\n";
                    echo "data: {$data}\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                if ($rows->isEmpty()) {
                    echo ": keepalive\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    sleep(3);
                }
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Constrain the query to entries with an exact match for the given tag,
     * via the indexed `probe_entry_tags` lookup table rather than a
     * leading-wildcard `LIKE` scan over the comma-separated `tags` column
     * (which also can't use a standard index and, unanchored, would match
     * e.g. a tag of "highest" when filtering for "high").
     */
    private function whereHasTag(Builder $query, string $tag): void
    {
        $query->whereIn('id', function (Builder $q) use ($tag) {
            $q->select('entry_id')->from('probe_entry_tags')->where('tag', $tag);
        });
    }

    /**
     * Fetch the raw `content` column for a set of row ids, keyed by id.
     *
     * Used to hydrate a grouped report's "sample" from the exact row a
     * `max(id)` aggregate identified as the group's most recent entry,
     * rather than an independent `min(content)`/`max(created_at)` pick
     * that can each resolve to a different physical row.
     */
    private function latestSamples(\Illuminate\Support\Collection $ids): \Illuminate\Support\Collection
    {
        $ids = $ids->filter()->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('probe_entries')
            ->whereIn('id', $ids)
            ->pluck('content', 'id');
    }

    /**
     * Generate a short human-readable summary line for a list row.
     */
    private function summarize(object $row): string
    {
        $content = json_decode($row->content, true) ?? [];

        return match ($row->type) {
            'requests'   => ($content['method'] ?? 'GET') . ' ' . ($content['uri'] ?? '') . ' — ' . ($content['status'] ?? ''),
            'queries'    => substr($content['sql'] ?? '', 0, 120),
            'exceptions' => ($content['class'] ?? 'Exception') . ': ' . substr($content['message'] ?? '', 0, 80),
            'jobs'       => ($content['name'] ?? 'Job') . ' on ' . ($content['queue'] ?? 'default') . ' [' . ($content['status'] ?? '') . ']',
            'cache'      => ($content['event'] ?? '') . ' → ' . ($content['key'] ?? ''),
            'schedule'   => ($content['command'] ?? '') . ' [' . ($content['status'] ?? '') . ']',
            default      => json_encode($content),
        };
    }
}
