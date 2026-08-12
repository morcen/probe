<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morcen\Probe\Storage\DatabaseDriver;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\QueryWatcher;
use Morcen\Probe\Watchers\ScheduleWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
    config()->set('probe.redact.body_fields', ['password', 'secret', 'token', 'api_key']);
});

function makeFakeScheduledTask(string $mutex = 'mutex-1', string $summary = 'php artisan test:command'): Event
{
    $task = Mockery::mock(Event::class);
    $task->shouldReceive('mutexName')->andReturn($mutex);
    $task->shouldReceive('getSummaryForDisplay')->andReturn($summary);

    return $task;
}

it('records a task immediately on ScheduledTaskStarting', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturn(20);

    $watcher = new ScheduleWatcher($storage);
    $watcher->register();

    $task = makeFakeScheduledTask();
    event(new ScheduledTaskStarting($task));
});

it('updates the stored content to completed via a parameterized query, preserving existing fields', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-2');
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.23));

    $row     = DB::table('probe_entries')->where('type', 'schedule')->first();
    $content = json_decode($row->content, true);

    expect($content['status'])->toBe('completed')
        ->and($content['duration_ms'])->toBeFloat()
        ->and($content['command'])->toBe('php artisan test:command')
        ->and($row->tags)->toBe('schedule,completed');
});

it('safely stores task output containing quotes and SQL-breaking characters on ScheduledTaskFinished', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-3');

    event(new ScheduledTaskStarting($task));

    $finished         = new ScheduledTaskFinished($task, 0.5);
    $finished->output = "'); DROP TABLE probe_entries; --";
    event($finished);

    $row     = DB::table('probe_entries')->where('type', 'schedule')->first();
    $content = json_decode($row->content, true);

    expect($content['status'])->toBe('completed')
        ->and($content['output'])->toBe("'); DROP TABLE probe_entries; --");

    expect(DB::table('probe_entries')->count())->toBe(1);
});

it('does not let finalizeEntry\'s own SELECT/UPDATE get captured by QueryWatcher as a new entry', function () {
    $storage = new DatabaseDriver();

    $scheduleWatcher = new ScheduleWatcher($storage);
    $scheduleWatcher->register();

    // Registered alongside QueryWatcher, as both are enabled by default —
    // finalizeEntry()'s internal SELECT/UPDATE must not be picked up and
    // stored as spurious 'queries' entries by the watcher below it.
    $queryWatcher = new QueryWatcher($storage);
    $queryWatcher->register();

    $task = makeFakeScheduledTask('mutex-8');
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.23));

    expect(DB::table('probe_entries')->pluck('type')->all())->toBe(['schedule']);
});

it('does not let a database failure inside finalizeEntry crash the host application', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-5');
    event(new ScheduledTaskStarting($task));

    Schema::dropIfExists('probe_entries');

    event(new ScheduledTaskFinished($task, 0.5));

    expect(true)->toBeTrue();
});

it('redacts sensitive values in the command string recorded on ScheduledTaskStarting', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-6', "php artisan sync:api --token=sk_live_abc123");
    event(new ScheduledTaskStarting($task));

    $row     = DB::table('probe_entries')->where('type', 'schedule')->first();
    $content = json_decode($row->content, true);

    expect($content['command'])->toBe('php artisan sync:api --token=[redacted]');
});

it('redacts sensitive values in the captured output on ScheduledTaskFinished', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-7');
    event(new ScheduledTaskStarting($task));

    $finished         = new ScheduledTaskFinished($task, 0.5);
    $finished->output = 'Authenticated with api_key: sk_live_abc123';
    event($finished);

    $row     = DB::table('probe_entries')->where('type', 'schedule')->first();
    $content = json_decode($row->content, true);

    expect($content['output'])->toBe('Authenticated with api_key: [redacted]');
});

it('marks the entry as failed on ScheduledTaskFailed', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-4');
    event(new ScheduledTaskStarting($task));

    event(new ScheduledTaskFailed($task, new Exception('boom')));

    $row     = DB::table('probe_entries')->where('type', 'schedule')->first();
    $content = json_decode($row->content, true);

    expect($content['status'])->toBe('failed')
        ->and($row->tags)->toBe('schedule,failed');
});
