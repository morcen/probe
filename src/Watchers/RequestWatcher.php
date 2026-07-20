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
        $body = $this->redactBody($body);

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return [substr($body, 0, self::MAX_BODY_BYTES), true];
        }

        return [$body, false];
    }

    /**
     * Redact configured sensitive fields from a JSON body. Non-JSON bodies
     * (e.g. plain text, multipart) are left untouched.
     */
    private function redactBody(string $body): string
    {
        $fields = config('probe.redact.body_fields', []);

        if (empty($fields)) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $body;
        }

        $encoded = json_encode($this->redactFields($decoded, $fields));

        return $encoded === false ? $body : $encoded;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param string[] $fields
     * @return array<array-key, mixed>
     */
    private function redactFields(array $data, array $fields): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveField($key, $fields)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactFields($value, $fields);
            }
        }

        return $data;
    }

    /**
     * @param string[] $fields
     */
    private function isSensitiveField(string $key, array $fields): bool
    {
        $key = strtolower($key);

        foreach ($fields as $field) {
            if (str_contains($key, strtolower($field))) {
                return true;
            }
        }

        return false;
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
