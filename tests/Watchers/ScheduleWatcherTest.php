<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morcen\Probe\Storage\DatabaseDriver;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\ScheduleWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
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

it('does not let a database failure inside finalizeEntry crash the host application', function () {
    $watcher = new ScheduleWatcher(new DatabaseDriver());
    $watcher->register();

    $task = makeFakeScheduledTask('mutex-5');
    event(new ScheduledTaskStarting($task));

    Schema::dropIfExists('probe_entries');

    event(new ScheduledTaskFinished($task, 0.5));

    expect(true)->toBeTrue();
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
