<?php

namespace Morcen\Probe\Http;

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
        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = DB::table('probe_entries')
            ->orderByDesc('id');

        if ($type) {
            $query->where('type', $type);
        }

        if ($tag) {
            $query->where('tags', 'LIKE', "%{$tag}%");
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
            ->selectRaw('family_hash, count(*) as occurrences, max(created_at) as last_seen, min(content) as sample')
            ->groupBy('family_hash')
            ->orderByDesc('occurrences')
            ->limit(100)
            ->get()
            ->map(function (object $row): array {
                $content = json_decode($row->sample, true) ?? [];
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
        $rows = DB::table('probe_entries')
            ->where('type', 'queries')
            ->where('tags', 'LIKE', '%slow%')
            ->selectRaw('family_hash, count(*) as occurrences, max(created_at) as last_seen, min(content) as sample')
            ->groupBy('family_hash')
            ->orderByDesc('occurrences')
            ->limit(50)
            ->get()
            ->map(function (object $row): array {
                $content = json_decode($row->sample, true) ?? [];
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
        $rows = DB::table('probe_entries')
            ->where('type', 'queries')
            ->where('tags', 'LIKE', '%n1%')
            ->selectRaw('family_hash, count(*) as total_executions, min(content) as sample')
            ->groupBy('family_hash')
            ->orderByDesc('total_executions')
            ->limit(50)
            ->get()
            ->map(function (object $row): array {
                $content = json_decode($row->sample, true) ?? [];
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
        $lastId = (int) $request->query('last_id', 0);

        return response()->stream(function () use ($lastId) {
            $current  = $lastId;
            $deadline = time() + 60;

            while (time() < $deadline) {
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
