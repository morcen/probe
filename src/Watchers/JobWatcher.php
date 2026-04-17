<?php

namespace Morcen\Probe\Watchers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class JobWatcher extends Watcher
{
    /**
     * Map of job ID => [probe_entry_id, start_time].
     *
     * @var array<string, array{entry_id: int, started_at: float}>
     */
    private array $jobs = [];

    public function register(): void
    {
        app('events')->listen(JobProcessing::class, function (JobProcessing $event) {
            $this->onProcessing($event);
        });

        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            $this->onProcessed($event);
        });

        app('events')->listen(JobFailed::class, function (JobFailed $event) {
            $this->onFailed($event);
        });
    }

    private function onProcessing(JobProcessing $event): void
    {
        $job     = $event->job;
        $jobId   = $job->getJobId();
        $payload = $job->payload();

        [$body, $truncated] = $this->capturePayload($payload);

        $entryId = $this->record(
            type: 'jobs',
            content: [
                'name'             => $payload['displayName'] ?? $job->resolveName(),
                'queue'            => $job->getQueue(),
                'connection'       => $event->connectionName,
                'status'           => 'processing',
                'payload'          => $body,
                'payload_truncated' => $truncated,
                'attempts'         => $job->attempts(),
                'duration_ms'      => null,
                'exception'        => null,
            ],
            tags: ['job', $job->getQueue(), 'processing'],
        );

        if ($jobId !== null && $entryId > 0) {
            $this->jobs[(string) $jobId] = [
                'entry_id'   => $entryId,
                'started_at' => microtime(true),
            ];
        }
    }

    private function onProcessed(JobProcessed $event): void
    {
        $jobId = (string) $event->job->getJobId();

        if (! isset($this->jobs[$jobId])) {
            return;
        }

        $meta        = $this->jobs[$jobId];
        $durationMs  = round((microtime(true) - $meta['started_at']) * 1000, 2);

        $this->updateEntry($meta['entry_id'], 'completed', $durationMs, null, $event->job->getQueue());
        unset($this->jobs[$jobId]);
    }

    private function onFailed(JobFailed $event): void
    {
        $jobId = (string) $event->job->getJobId();

        if (! isset($this->jobs[$jobId])) {
            return;
        }

        $meta       = $this->jobs[$jobId];
        $durationMs = round((microtime(true) - $meta['started_at']) * 1000, 2);

        $this->updateEntry(
            $meta['entry_id'],
            'failed',
            $durationMs,
            $event->exception->getMessage(),
            $event->job->getQueue()
        );

        unset($this->jobs[$jobId]);
    }

    private function updateEntry(
        int $entryId,
        string $status,
        float $durationMs,
        ?string $exception,
        string $queue
    ): void {
        \Illuminate\Support\Facades\DB::table('probe_entries')
            ->where('id', $entryId)
            ->update([
                'tags' => implode(',', array_filter(['job', $queue, $status])),
                'content' => \Illuminate\Support\Facades\DB::raw(
                    "JSON_SET(content, '$.status', '{$status}', '$.duration_ms', {$durationMs}"
                    . ($exception !== null ? ", '$.exception', '" . addslashes($exception) . "'" : '')
                    . ')'
                ),
            ]);
    }

    /**
     * @param array<mixed> $payload
     * @return array{string, bool}
     */
    private function capturePayload(array $payload): array
    {
        $json = json_encode($payload) ?: '';
        $max  = 10240; // 10 KB

        if (strlen($json) > $max) {
            return [substr($json, 0, $max), true];
        }

        return [$json, false];
    }
}
