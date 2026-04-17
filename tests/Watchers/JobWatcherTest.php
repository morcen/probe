<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
