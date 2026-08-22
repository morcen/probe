<?php

namespace Morcen\Probe\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertDispatcher
{
    /**
     * Maximum seconds to wait for a Slack/webhook alert connection to
     * establish. Alert delivery must never stall the watched
     * request/job/command it's reporting on, so this is kept short and
     * bounded rather than left to Guzzle's default of "no timeout at all".
     */
    private const CONNECT_TIMEOUT = 2;

    /**
     * Maximum seconds to wait for the full Slack/webhook alert request
     * (connect + response) to complete.
     */
    private const REQUEST_TIMEOUT = 3;

    /**
     * Dispatch an alert for the given entry if any configured rules match.
     *
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    public function dispatch(string $type, array $content, array $tags): void
    {
        $rules = config('probe.alerts', []);

        if (empty($rules)) {
            return;
        }

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $type, $tags)) {
                continue;
            }

            $channel = strtolower((string) ($rule['channel'] ?? 'log'));

            if (! in_array($channel, ['slack', 'webhook', 'log'], true)) {
                Log::warning('[Probe] Unsupported alert channel "' . ($rule['channel'] ?? '')
                    . '" configured for a "' . $type . '" rule; falling back to the "log" channel.');

                $channel = 'log';
            }

            match ($channel) {
                'slack'   => $this->sendSlack($rule, $type, $content, $tags),
                'webhook' => $this->sendWebhook($rule, $type, $content, $tags),
                default   => $this->sendLog($type, $content, $tags),
            };
        }
    }

    /**
     * @param array<mixed> $rule
     * @param string[]     $tags
     */
    private function matches(array $rule, string $type, array $tags): bool
    {
        // Must match entry type.
        $ruleTypes = (array) ($rule['types'] ?? []);

        if (! empty($ruleTypes) && ! in_array($type, $ruleTypes, true)) {
            return false;
        }

        // Must contain at least one required tag if specified.
        $requiredTags = (array) ($rule['tags'] ?? []);

        if (! empty($requiredTags)) {
            $intersection = array_intersect($requiredTags, $tags);
            if (empty($intersection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $rule
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    private function sendSlack(array $rule, string $type, array $content, array $tags): void
    {
        $url = $rule['url'] ?? null;

        if (! $url) {
            return;
        }

        $text = $this->buildMessage($type, $content, $tags);

        try {
            Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT)->post($url, [
                'text' => $text,
                'username' => 'Probe | Laravel',
                'icon_emoji' => ':mag:',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Probe] Slack alert failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<mixed> $rule
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    private function sendWebhook(array $rule, string $type, array $content, array $tags): void
    {
        $url = $rule['url'] ?? null;

        if (! $url) {
            return;
        }

        try {
            Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::REQUEST_TIMEOUT)->post($url, [
                'type'    => $type,
                'tags'    => $tags,
                'content' => $content,
                'app'     => config('app.name'),
                'env'     => config('app.env'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Probe] Webhook alert failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    private function sendLog(string $type, array $content, array $tags): void
    {
        Log::warning('[Probe alert] ' . $type . ' | tags: ' . implode(', ', $tags), $content);
    }

    /**
     * @param array<mixed> $content
     * @param string[]     $tags
     */
    private function buildMessage(string $type, array $content, array $tags): string
    {
        $app  = config('app.name', 'Laravel');
        $env  = config('app.env', 'production');
        $line = match ($type) {
            'exceptions' => ($content['class'] ?? 'Exception') . ': ' . ($content['message'] ?? ''),
            'queries'    => 'Slow query (' . ($content['duration_ms'] ?? '?') . 'ms): ' . substr($content['sql'] ?? '', 0, 100),
            'jobs'       => 'Job failed: ' . ($content['name'] ?? '') . ' on ' . ($content['queue'] ?? 'default'),
            default      => $type . ' alert',
        };

        return "[{$app} / {$env}] {$line} (tags: " . implode(', ', $tags) . ')';
    }
}
