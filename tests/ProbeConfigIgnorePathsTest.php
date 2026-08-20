<?php

/**
 * The default requests.ignore_paths must track the configured PROBE_PATH so
 * that the dashboard's own traffic is skipped wherever it is mounted, rather
 * than only when it is left at the default 'probe' prefix. Re-evaluating the
 * config file with the env var set exercises the default expression directly.
 */

function loadProbeConfigWith(?string $probePath): array
{
    if ($probePath === null) {
        putenv('PROBE_PATH');
        unset($_ENV['PROBE_PATH'], $_SERVER['PROBE_PATH']);
    } else {
        putenv('PROBE_PATH=' . $probePath);
        $_ENV['PROBE_PATH']    = $probePath;
        $_SERVER['PROBE_PATH'] = $probePath;
    }

    try {
        return require __DIR__ . '/../config/probe.php';
    } finally {
        putenv('PROBE_PATH');
        unset($_ENV['PROBE_PATH'], $_SERVER['PROBE_PATH']);
    }
}

it('defaults ignore_paths to the default probe prefix when PROBE_PATH is unset', function () {
    $config = loadProbeConfigWith(null);

    expect($config['watchers_config']['requests']['ignore_paths'])
        ->toContain('probe/*');
});

it('derives the ignore_paths dashboard entry from a custom PROBE_PATH', function () {
    $config = loadProbeConfigWith('admin/debug-panel');

    $ignorePaths = $config['watchers_config']['requests']['ignore_paths'];

    expect($ignorePaths)->toContain('admin/debug-panel/*')
        ->and($ignorePaths)->not->toContain('probe/*')
        ->and($ignorePaths)->toContain('_ignition/*')
        ->and($ignorePaths)->toContain('telescope/*');
});

it('normalizes a trailing slash on a custom PROBE_PATH', function () {
    $config = loadProbeConfigWith('admin/debug-panel/');

    expect($config['watchers_config']['requests']['ignore_paths'])
        ->toContain('admin/debug-panel/*');
});
