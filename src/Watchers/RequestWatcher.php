<?php

namespace Morcen\Probe\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Auth;

class RequestWatcher extends Watcher
{
    private const MAX_BODY_BYTES = 65536; // 64 KB

    public function register(): void
    {
        app('events')->listen(RequestHandled::class, function (RequestHandled $event) {
            $this->onRequestHandled($event);
        });
    }

    private function onRequestHandled(RequestHandled $event): void
    {
        $request  = $event->request;
        $response = $event->response;
        $uri      = $request->path();

        if ($this->isIgnoredPath($uri)) {
            return;
        }

        $ignoredCodes = config('probe.watchers_config.requests.ignore_status_codes', []);
        $statusCode   = $response->getStatusCode();

        if (in_array($statusCode, $ignoredCodes, true)) {
            return;
        }

        $startTime   = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $durationMs  = $startTime ? round((microtime(true) - (float) $startTime) * 1000, 2) : null;

        [$requestBody, $requestTruncated]   = $this->captureBody((string) $request->getContent());
        [$responseBody, $responseTruncated] = $this->captureBody((string) $response->getContent());

        $this->record(
            type: 'requests',
            content: [
                'method'              => $request->method(),
                'uri'                 => $uri,
                'url'                 => $request->fullUrl(),
                'status'              => $statusCode,
                'duration_ms'         => $durationMs,
                'user_id'             => Auth::id(),
                'ip'                  => $request->ip(),
                'headers'             => $this->sanitizeHeaders($request->headers->all()),
                'payload'             => $requestBody,
                'payload_truncated'   => $requestTruncated,
                'response'            => $responseBody,
                'response_truncated'  => $responseTruncated,
            ],
            tags: ['request', strtolower($request->method()), (string) $statusCode],
        );
    }

    /**
     * @return array{string, bool}
     */
    private function captureBody(string $body): array
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return [substr($body, 0, self::MAX_BODY_BYTES), true];
        }

        return [$body, false];
    }

    /**
     * Strip sensitive header values but keep the header keys.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token'];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitive, true)) {
                $headers[$key] = ['[redacted]'];
            }
        }

        return $headers;
    }
}
