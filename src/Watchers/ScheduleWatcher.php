<?php

namespace Morcen\Probe\Watchers;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

class ScheduleWatcher extends Watcher
{
    /**
     * Map of task mutex => [entry_id, started_at].
     *
     * @var array<string, array{entry_id: int, started_at: float}>
     */
    private array $tasks = [];

    public function register(): void
    {
        app('events')->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $this->onStarting($event);
        });

        app('events')->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $this->onFinished($event);
        });

        app('events')->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $this->onFailed($event);
        });
    }

    private function onStarting(ScheduledTaskStarting $event): void
    {
        $task   = $event->task;
        $mutex  = $task->mutexName();

        $entryId = $this->record(
            type: 'schedule',
            content: [
                'command'    => $task->getSummaryForDisplay(),
                'expression' => $task->expression ?? null,
                'status'     => 'running',
                'duration_ms' => null,
                'output'     => null,
            ],
            tags: ['schedule', 'running'],
        );

        if ($entryId > 0) {
            $this->tasks[$mutex] = [
                'entry_id'   => $entryId,
                'started_at' => microtime(true),
            ];
        }
    }

    private function onFinished(ScheduledTaskFinished $event): void
    {
        $mutex = $event->task->mutexName();

        if (! isset($this->tasks[$mutex])) {
            return;
        }

        $meta       = $this->tasks[$mutex];
        $durationMs = round((microtime(true) - $meta['started_at']) * 1000, 2);
        $output     = $this->captureOutput($event->output ?? '');

        $this->finalizeEntry($meta['entry_id'], 'completed', $durationMs, $output, $event->task->getSummaryForDisplay());
        unset($this->tasks[$mutex]);
    }

    private function onFailed(ScheduledTaskFailed $event): void
    {
        $mutex = $event->task->mutexName();

        if (! isset($this->tasks[$mutex])) {
            return;
        }

        $meta       = $this->tasks[$mutex];
        $durationMs = round((microtime(true) - $meta['started_at']) * 1000, 2);

        $this->finalizeEntry($meta['entry_id'], 'failed', $durationMs, null, $event->task->getSummaryForDisplay());
        unset($this->tasks[$mutex]);
    }

    private function finalizeEntry(
        int $entryId,
        string $status,
        float $durationMs,
        ?string $output,
        string $command
    ): void {
        \Illuminate\Support\Facades\DB::table('probe_entries')
            ->where('id', $entryId)
            ->update([
                'tags' => implode(',', ['schedule', $status]),
                'content' => \Illuminate\Support\Facades\DB::raw(
                    "JSON_SET(content, '$.status', '{$status}', '$.duration_ms', {$durationMs}"
                    . ($output !== null ? ", '$.output', '" . addslashes($output) . "'" : '')
                    . ')'
                ),
            ]);
    }

    private function captureOutput(string $output): string
    {
        $max = 2048;

        if (strlen($output) > $max) {
            return substr($output, -$max); // Keep the tail (most recent output)
        }

        return $output;
    }
}
