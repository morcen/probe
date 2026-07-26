<?php

use Illuminate\Log\Events\MessageLogged;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\ExceptionWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
    config()->set('probe.watchers_config.exceptions.ignore_exceptions', []);
    config()->set('probe.watchers_config.exceptions.strip_vendor_frames', false);
    config()->set('probe.watchers_config.exceptions.redact_message', false);
});

it('records an exception from a log error event', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturn(1);

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    $exception = new RuntimeException('Something broke', 0);

    event(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception]));
});

it('stores the correct family_hash for deduplication', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    $exception = new RuntimeException('Boom');

    $expectedHash = md5(get_class($exception) . $exception->getFile() . $exception->getLine());

    event(new MessageLogged('error', 'Boom', ['exception' => $exception]));

    expect($captured['family_hash'])->toBe($expectedHash);
});

it('ignores non-error log levels', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    event(new MessageLogged('info', 'Just info', ['exception' => new RuntimeException('x')]));
});

it('ignores configured exception classes', function () {
    config()->set('probe.watchers_config.exceptions.ignore_exceptions', [
        InvalidArgumentException::class,
    ]);

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    $exception = new InvalidArgumentException('ignored');

    event(new MessageLogged('error', 'ignored', ['exception' => $exception]));
});

it('ignores log events without an exception in context', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    event(new MessageLogged('error', 'plain error string', []));
});

it('redacts sensitive values interpolated into the exception message', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    $exception = new RuntimeException('Invalid API token: sk_live_abc123');

    event(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception]));

    expect($captured['content']['message'])->toBe('Invalid API token: [redacted]');
});

it('leaves an exception message without a key/value shape untouched', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    $exception = new RuntimeException('Something broke');

    event(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception]));

    expect($captured['content']['message'])->toBe('Something broke');
});

it('strips the exception message entirely when redact_message is enabled', function () {
    config()->set('probe.watchers_config.exceptions.redact_message', true);

    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new ExceptionWatcher($storage);
    $watcher->register();

    $exception = new RuntimeException('Invalid API token: sk_live_abc123');

    event(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception]));

    expect($captured['content']['message'])->toBe('[redacted]');
});
