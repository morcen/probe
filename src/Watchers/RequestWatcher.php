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
                'url'                 => $this->redactUrl($request->fullUrl()),
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
     * Redact configured sensitive fields from a URL's query string. The path
     * and any non-query portion of the URL are left untouched.
     */
    private function redactUrl(string $url): string
    {
        $queryStart = strpos($url, '?');

        if ($queryStart === false) {
            return $url;
        }

        $fields = config('probe.redact.body_fields', []);

        if (empty($fields)) {
            return $url;
        }

        parse_str(substr($url, $queryStart + 1), $params);

        if (empty($params)) {
            return $url;
        }

        $redactedQuery = http_build_query($this->redactFields($params, $fields));

        return substr($url, 0, $queryStart) . '?' . $redactedQuery;
    }

    /**
     * Strip sensitive header values but keep the header keys.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = config('probe.redact.headers', []);

        foreach ($headers as $key => $value) {
            if ($this->isSensitiveField((string) $key, $sensitive)) {
                $headers[$key] = ['[redacted]'];
            }
        }

        return $headers;
    }
}
