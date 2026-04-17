<?php

namespace Morcen\Probe\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertDispatcher
{
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

            $channel = $rule['channel'] ?? 'log';

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
            Http::post($url, [
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
            Http::post($url, [
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
