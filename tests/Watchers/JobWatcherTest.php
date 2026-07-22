<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Morcen\Probe\Storage\DatabaseDriver;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\JobWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
});

function makeFakeJob(string $id = 'job-1', string $queue = 'default'): object
{
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn($id);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendEmail');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\SendEmail',
        'data'        => ['userId' => 42],
    ]);

    return $job;
}

it('records a job immediately on JobProcessing without manual startRecording', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturn(10);

    $watcher = new JobWatcher($storage);
    $watcher->register();

    $job = makeFakeJob();
    event(new JobProcessing('redis', $job));
});

it('updates the entry to completed on JobProcessed', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturn(10);

    $watcher = new JobWatcher($storage);
    $watcher->register();

    $job = makeFakeJob('job-2');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));
    // No assertion beyond no exception thrown — DB update tested via integration test.
});

it('records nothing when sampling rate is 0', function () {
    config()->set('probe.sampling_rate', 0.0);

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new JobWatcher($storage);
    $watcher->register();

    $job = makeFakeJob('job-3');
    event(new JobProcessing('redis', $job));
});

it('updates the stored content to completed via a parameterized query, preserving existing fields', function () {
    $watcher = new JobWatcher(new DatabaseDriver());
    $watcher->register();

    $job = makeFakeJob('job-4');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    $row     = DB::table('probe_entries')->where('type', 'jobs')->first();
    $content = json_decode($row->content, true);

    expect($content['status'])->toBe('completed')
        ->and($content['duration_ms'])->toBeFloat()
        ->and($content['name'])->toBe('App\\Jobs\\SendEmail')
        ->and($row->tags)->toBe('job,default,completed');
});

it('redacts sensitive fields in the job payload data', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new JobWatcher($storage);
    $watcher->register();

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-6');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ResetPassword');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\ResetPassword',
        'data'        => [
            'email' => 'user@example.com',
            'token' => 'super-secret-reset-token',
        ],
    ]);

    event(new JobProcessing('redis', $job));

    $content = json_decode($captured['content']['payload'], true);

    expect($content['data']['email'])->toBe('user@example.com')
        ->and($content['data']['token'])->toBe('[redacted]');
});

it('safely stores an exception message containing quotes and SQL-breaking characters on JobFailed', function () {
    $watcher = new JobWatcher(new DatabaseDriver());
    $watcher->register();

    $job = makeFakeJob('job-5');
    event(new JobProcessing('redis', $job));

    $malicious = "'); DROP TABLE probe_entries; --";
    $exception = new Exception($malicious);

    event(new JobFailed('redis', $job, $exception));

    $row     = DB::table('probe_entries')->where('type', 'jobs')->first();
    $content = json_decode($row->content, true);

    expect($content['status'])->toBe('failed')
        ->and($content['exception'])->toBe($malicious);

    expect(DB::table('probe_entries')->count())->toBe(1);
});
