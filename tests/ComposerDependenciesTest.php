<?php

it('declares illuminate/log and illuminate/routing, which are used directly but were missing', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
    $required = array_keys($composer['require']);

    // Illuminate\Log\Events\MessageLogged is imported in ExceptionWatcher, and
    // Illuminate\Routing\Controller is extended by ProbeController. Both ship
    // as independently versioned illuminate/* packages, so they belong in
    // `require` alongside the package's other illuminate/* dependencies.
    //
    // Note: illuminate/foundation (Illuminate\Foundation\Http\Events\RequestHandled,
    // used in RequestWatcher/QueryWatcher) is deliberately NOT required here: unlike
    // the packages above, it isn't published as an independently versioned split
    // package for Laravel 10-12 (its latest release on Packagist is the abandoned
    // v1.1.2 from 2012), so a matching constraint doesn't exist to require.
    expect($required)->toContain('illuminate/log')
        ->and($required)->toContain('illuminate/routing');
});

it('declares every illuminate/* class used as a base class or interface in src/', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
    $required = array_keys($composer['require']);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        // Map short names imported via `use` to their fully-qualified class,
        // since `extends`/`implements` reference the short alias, not the FQCN.
        preg_match_all('/^use (Illuminate\\\\[A-Za-z0-9\\\\]+);/m', $source, $uses);
        $aliases = [];

        foreach ($uses[1] as $fqcn) {
            $segments             = explode('\\', $fqcn);
            $aliases[end($segments)] = $fqcn;
        }

        // `extends`/`implements` resolve the parent/interface eagerly when the
        // class is autoloaded, so any illuminate/* class used this way must be
        // backed by a declared, independently installable package.
        preg_match_all('/(?:extends|implements)\s+([A-Za-z0-9_\\\\]+)/', $source, $matches);

        foreach ($matches[1] as $name) {
            $fqcn = $aliases[$name] ?? $name;

            if (! str_starts_with($fqcn, 'Illuminate\\')) {
                continue;
            }

            $segments = explode('\\', $fqcn);
            $package  = 'illuminate/'.strtolower($segments[1]);

            expect($required)->toContain($package);
        }
    }
});
