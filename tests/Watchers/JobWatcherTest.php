<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morcen\Probe\Storage\DatabaseDriver;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\JobWatcher;
use Morcen\Probe\Watchers\QueryWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
});

class FakeResetPasswordJob
{
    public function __construct(public string $email, public string $token)
    {
    }
}

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

it('redacts sensitive constructor properties from a real serialized job command payload', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new JobWatcher($storage);
    $watcher->register();

    $command = new FakeResetPasswordJob('user@example.com', 'super-secret-reset-token');

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('getJobId')->andReturn('job-7');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ResetPassword');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([
        'displayName' => 'App\\Jobs\\ResetPassword',
        'data'        => [
            'commandName' => FakeResetPasswordJob::class,
            'command'     => serialize($command),
        ],
    ]);

    event(new JobProcessing('redis', $job));

    $content = json_decode($captured['content']['payload'], true);
    $decoded = $content['data']['command'];

    expect($decoded)->toBeArray()
        ->and($decoded['email'])->toBe('user@example.com')
        ->and($decoded['token'])->toBe('[redacted]');
});

it('does not let a database failure inside updateEntry crash the host application', function () {
    $watcher = new JobWatcher(new DatabaseDriver());
    $watcher->register();

    $job = makeFakeJob('job-9');
    event(new JobProcessing('redis', $job));

    Schema::dropIfExists('probe_entries');

    event(new JobProcessed('redis', $job));

    expect(true)->toBeTrue();
});

it('redacts sensitive values interpolated into the failure exception message', function () {
    $watcher = new JobWatcher(new DatabaseDriver());
    $watcher->register();

    $job = makeFakeJob('job-8');
    event(new JobProcessing('redis', $job));

    $exception = new Exception('Invalid API token: sk_live_abc123');
    event(new JobFailed('redis', $job, $exception));

    $row     = DB::table('probe_entries')->where('type', 'jobs')->first();
    $content = json_decode($row->content, true);

    expect($content['status'])->toBe('failed')
        ->and($content['exception'])->toBe('Invalid API token: [redacted]');
});

it('does not let updateEntry\'s own SELECT/UPDATE get captured by QueryWatcher as a new entry', function () {
    $storage = new DatabaseDriver();

    $jobWatcher = new JobWatcher($storage);
    $jobWatcher->register();

    // Registered alongside QueryWatcher, as both are enabled by default —
    // updateEntry()'s internal SELECT/UPDATE must not be picked up and
    // stored as spurious 'queries' entries by the watcher below it.
    $queryWatcher = new QueryWatcher($storage);
    $queryWatcher->register();

    $job = makeFakeJob('job-10');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(DB::table('probe_entries')->pluck('type')->all())->toBe(['jobs']);
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
