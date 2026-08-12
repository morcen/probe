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
        $this->safely(function () use ($event) {
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
        });
    }

    private function onProcessed(JobProcessed $event): void
    {
        $this->safely(function () use ($event) {
            $jobId = (string) $event->job->getJobId();

            if (! isset($this->jobs[$jobId])) {
                return;
            }

            $meta        = $this->jobs[$jobId];
            $durationMs  = round((microtime(true) - $meta['started_at']) * 1000, 2);

            $this->updateEntry($meta['entry_id'], 'completed', $durationMs, null, $event->job->getQueue());
            unset($this->jobs[$jobId]);
        });
    }

    private function onFailed(JobFailed $event): void
    {
        $this->safely(function () use ($event) {
            $jobId = (string) $event->job->getJobId();

            if (! isset($this->jobs[$jobId])) {
                return;
            }

            $meta       = $this->jobs[$jobId];
            $durationMs = round((microtime(true) - $meta['started_at']) * 1000, 2);

            $fields = config('probe.redact.body_fields', []);

            $this->updateEntry(
                $meta['entry_id'],
                'failed',
                $durationMs,
                $this->redactMessage($event->exception->getMessage(), $fields),
                $event->job->getQueue()
            );

            unset($this->jobs[$jobId]);
        });
    }

    private function updateEntry(
        int $entryId,
        string $status,
        float $durationMs,
        ?string $exception,
        string $queue
    ): void {
        $this->withoutRecording(function () use ($entryId, $status, $durationMs, $exception, $queue) {
            $raw     = \Illuminate\Support\Facades\DB::table('probe_entries')->where('id', $entryId)->value('content');
            $content = json_decode($raw ?? '', true) ?? [];

            $content['status']      = $status;
            $content['duration_ms'] = $durationMs;

            if ($exception !== null) {
                $content['exception'] = $exception;
            }

            \Illuminate\Support\Facades\DB::table('probe_entries')
                ->where('id', $entryId)
                ->update([
                    'tags'    => implode(',', array_filter(['job', $queue, $status])),
                    'content' => json_encode($content),
                ]);
        });
    }

    /**
     * @param array<mixed> $payload
     * @return array{string, bool}
     */
    private function capturePayload(array $payload): array
    {
        $fields = config('probe.redact.body_fields', []);

        if (! empty($fields) && isset($payload['data']) && is_array($payload['data'])) {
            $payload['data'] = $this->redactFields($payload['data'], $fields);

            if (isset($payload['data']['command']) && is_string($payload['data']['command'])) {
                $payload['data']['command'] = $this->redactSerializedCommand($payload['data']['command'], $fields);
            }
        }

        $json = json_encode($payload) ?: '';
        $max  = 10240; // 10 KB

        if (strlen($json) > $max) {
            return [substr($json, 0, $max), true];
        }

        return [$json, false];
    }

    /**
     * Illuminate\Queue\Queue::createObjectPayload() serializes the entire job
     * object into `data.command`, so field-name redaction on the outer array
     * never reaches sensitive constructor properties (tokens, passwords,
     * etc.) hidden inside that string. Best-effort unserialize it (with all
     * classes disallowed, so nothing is ever instantiated) and redact any
     * matching property by name, returning a safe display representation.
     * Falls back to the original string if it can't be safely parsed.
     *
     * @param string[] $fields
     * @return array<array-key, mixed>|string
     */
    private function redactSerializedCommand(string $command, array $fields): array|string
    {
        try {
            $value = @unserialize($command, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return $command;
        }

        if ($value === false && $command !== serialize(false)) {
            return $command;
        }

        if (! ($value instanceof \__PHP_Incomplete_Class) && ! is_array($value)) {
            return $command;
        }

        return $this->redactUnserializedValue($value, $fields);
    }

    /**
     * @param string[] $fields
     */
    private function redactUnserializedValue(mixed $value, array $fields): mixed
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            $value = (array) $value;
            unset($value['__PHP_Incomplete_Class_Name']);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? preg_replace('/^\0.*?\0/', '', $key) : $key;

            if (is_string($cleanKey) && $this->isSensitiveField($cleanKey, $fields)) {
                $redacted[$cleanKey] = '[redacted]';
                continue;
            }

            $redacted[$cleanKey] = $this->redactUnserializedValue($item, $fields);
        }

        return $redacted;
    }
}
