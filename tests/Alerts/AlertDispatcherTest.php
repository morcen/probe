<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Morcen\Probe\Alerts\AlertDispatcher;

// AlertDispatcher must never let a slow/unreachable Slack or webhook
// endpoint stall the request/job/command it's reporting on, so every
// outbound alert call is bounded by an explicit connect + request timeout
// rather than Guzzle's default of "no timeout at all".

beforeEach(function () {
    config()->set('probe.alerts', []);
});

it('bounds slack alerts with an explicit connect and request timeout', function () {
    Http::shouldReceive('connectTimeout')->once()->with(2)->andReturnSelf();
    Http::shouldReceive('timeout')->once()->with(3)->andReturnSelf();
    Http::shouldReceive('post')
        ->once()
        ->with('https://hooks.slack.test/abc', Mockery::type('array'))
        ->andReturn(null);

    config()->set('probe.alerts', [
        ['types' => ['exceptions'], 'channel' => 'slack', 'url' => 'https://hooks.slack.test/abc'],
    ]);

    (new AlertDispatcher())->dispatch('exceptions', ['class' => 'RuntimeException', 'message' => 'boom'], []);
});

it('bounds webhook alerts with an explicit connect and request timeout', function () {
    Http::shouldReceive('connectTimeout')->once()->with(2)->andReturnSelf();
    Http::shouldReceive('timeout')->once()->with(3)->andReturnSelf();
    Http::shouldReceive('post')
        ->once()
        ->with('https://example.test/webhook', Mockery::type('array'))
        ->andReturn(null);

    config()->set('probe.alerts', [
        ['types' => ['jobs'], 'tags' => ['failed'], 'channel' => 'webhook', 'url' => 'https://example.test/webhook'],
    ]);

    (new AlertDispatcher())->dispatch('jobs', ['name' => 'SendInvoice'], ['failed']);
});

it('does not let a timed-out slack alert crash the host application', function () {
    Http::shouldReceive('connectTimeout')->once()->with(2)->andReturnSelf();
    Http::shouldReceive('timeout')->once()->with(3)->andReturnSelf();
    Http::shouldReceive('post')
        ->once()
        ->andThrow(new ConnectionException('cURL error 28: Operation timed out'));

    config()->set('probe.alerts', [
        ['types' => ['exceptions'], 'channel' => 'slack', 'url' => 'https://hooks.slack.test/abc'],
    ]);

    (new AlertDispatcher())->dispatch('exceptions', ['class' => 'RuntimeException', 'message' => 'boom'], []);

    expect(true)->toBeTrue();
});

it('does not let a timed-out webhook alert crash the host application', function () {
    Http::shouldReceive('connectTimeout')->once()->with(2)->andReturnSelf();
    Http::shouldReceive('timeout')->once()->with(3)->andReturnSelf();
    Http::shouldReceive('post')
        ->once()
        ->andThrow(new ConnectionException('cURL error 28: Operation timed out'));

    config()->set('probe.alerts', [
        ['types' => ['jobs'], 'channel' => 'webhook', 'url' => 'https://example.test/webhook'],
    ]);

    (new AlertDispatcher())->dispatch('jobs', ['name' => 'SendInvoice'], []);

    expect(true)->toBeTrue();
});

it('logs the alert as-is when the channel is explicitly "log"', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/^\[Probe alert\] /'), Mockery::type('array'));
    Http::shouldReceive('post')->never();

    config()->set('probe.alerts', [
        ['types' => ['queries'], 'tags' => ['slow'], 'channel' => 'log'],
    ]);

    (new AlertDispatcher())->dispatch('queries', ['sql' => 'select * from users'], ['slow']);
});

it('normalizes a differently-cased channel instead of treating it as unsupported', function () {
    Http::shouldReceive('connectTimeout')->once()->with(2)->andReturnSelf();
    Http::shouldReceive('timeout')->once()->with(3)->andReturnSelf();
    Http::shouldReceive('post')
        ->once()
        ->with('https://hooks.slack.test/abc', Mockery::type('array'))
        ->andReturn(null);
    Log::shouldReceive('warning')->never();

    config()->set('probe.alerts', [
        ['types' => ['exceptions'], 'channel' => 'Slack', 'url' => 'https://hooks.slack.test/abc'],
    ]);

    (new AlertDispatcher())->dispatch('exceptions', ['class' => 'RuntimeException', 'message' => 'boom'], []);
});

it('warns and falls back to the log channel for an unsupported channel value', function () {
    Http::shouldReceive('post')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/^\[Probe\] Unsupported alert channel "slak" configured for a "exceptions" rule; falling back to the "log" channel\.$/'));
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/^\[Probe alert\] /'), Mockery::type('array'));

    config()->set('probe.alerts', [
        ['types' => ['exceptions'], 'channel' => 'slak', 'url' => 'https://hooks.slack.test/abc'],
    ]);

    (new AlertDispatcher())->dispatch('exceptions', ['class' => 'RuntimeException', 'message' => 'boom'], []);
});

it('skips a rule whose types do not include the entry type', function () {
    Http::shouldReceive('post')->never();
    Log::shouldReceive('warning')->never();

    config()->set('probe.alerts', [
        ['types' => ['exceptions'], 'channel' => 'log'],
    ]);

    (new AlertDispatcher())->dispatch('queries', ['sql' => 'select 1'], []);
});

it('applies a rule with no types restriction to every entry type', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/^\[Probe alert\] /'), Mockery::type('array'));

    config()->set('probe.alerts', [
        ['channel' => 'log'],
    ]);

    (new AlertDispatcher())->dispatch('cache', ['event' => 'hit'], []);
});

it('skips a rule whose required tags are absent from the entry', function () {
    Http::shouldReceive('post')->never();
    Log::shouldReceive('warning')->never();

    config()->set('probe.alerts', [
        ['types' => ['jobs'], 'tags' => ['failed'], 'channel' => 'log'],
    ]);

    (new AlertDispatcher())->dispatch('jobs', ['name' => 'SendInvoice'], ['completed']);
});

it('matches a rule when at least one of its required tags is present', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/^\[Probe alert\] /'), Mockery::type('array'));

    config()->set('probe.alerts', [
        ['types' => ['jobs'], 'tags' => ['failed', 'retried'], 'channel' => 'log'],
    ]);

    (new AlertDispatcher())->dispatch('jobs', ['name' => 'SendInvoice'], ['retried']);
});

it('applies a rule with no tags restriction regardless of the entry tags', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/^\[Probe alert\] /'), Mockery::type('array'));

    config()->set('probe.alerts', [
        ['types' => ['jobs'], 'channel' => 'log'],
    ]);

    (new AlertDispatcher())->dispatch('jobs', ['name' => 'SendInvoice'], []);
});
