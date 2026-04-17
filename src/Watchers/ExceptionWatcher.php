<?php

namespace Morcen\Probe\Watchers;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ExceptionWatcher extends Watcher
{
    public function register(): void
    {
        app('events')->listen(MessageLogged::class, function (MessageLogged $event) {
            $this->onMessageLogged($event);
        });
    }

    private function onMessageLogged(MessageLogged $event): void
    {
        if (! in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            return;
        }

        $exception = $event->context['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            return;
        }

        $ignored = config('probe.watchers_config.exceptions.ignore_exceptions', []);

        foreach ($ignored as $class) {
            if ($exception instanceof $class) {
                return;
            }
        }

        $class       = get_class($exception);
        $file        = $exception->getFile();
        $line        = $exception->getLine();
        $familyHash  = md5($class . $file . $line);
        $shortClass  = class_basename($class);

        $this->record(
            type: 'exceptions',
            content: [
                'class'   => $class,
                'message' => $exception->getMessage(),
                'file'    => $file,
                'line'    => $line,
                'trace'   => $this->formatTrace($exception),
                'user_id' => Auth::id(),
                'level'   => $event->level,
            ],
            tags: ['exception', $shortClass],
            familyHash: $familyHash,
        );
    }

    /**
     * Return the first 20 frames, vendor frames optionally stripped.
     *
     * @return array<int, array{file: string, line: int, function: string}>
     */
    private function formatTrace(Throwable $e): array
    {
        $frames     = $e->getTrace();
        $stripVendor = config('probe.watchers_config.exceptions.strip_vendor_frames', false);

        if ($stripVendor) {
            $frames = array_filter($frames, function (array $frame): bool {
                $file = $frame['file'] ?? '';
                return ! str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
            });
            $frames = array_values($frames);
        }

        return array_map(function (array $frame): array {
            return [
                'file'     => $frame['file'] ?? '[internal]',
                'line'     => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }, array_slice($frames, 0, 20));
    }
}
