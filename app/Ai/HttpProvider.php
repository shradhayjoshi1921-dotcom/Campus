<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * OpenAI-compatible chat-completions client written against cURL only, so the
 * project keeps its "no composer dependencies" rule.
 *
 * Every setting comes from the environment at call time; nothing is hard-coded
 * and no key ever reaches a template. A failure of any kind (no network, DNS,
 * timeout, HTTP error, malformed JSON) is returned as an AiResult, never thrown.
 */
final class HttpProvider implements AiProvider
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
        private int $timeoutSeconds = 20,
        private string $providerName = 'openai-compatible',
    ) {
    }

    public static function fromEnv(array $env): ?self
    {
        $key = trim((string) ($env['AI_API_KEY'] ?? ''));
        if ($key === '') {
            return null;
        }
        $base = rtrim(trim((string) ($env['AI_BASE_URL'] ?? 'https://api.openai.com/v1')), '/');
        $model = trim((string) ($env['AI_MODEL'] ?? 'gpt-4o-mini'));
        if ($base === '' || $model === '' || !preg_match('~^https?://~i', $base)) {
            return null;
        }
        $timeout = (int) ($env['AI_TIMEOUT'] ?? 20);
        return new self($base, $key, $model, max(5, min(60, $timeout)), (string) ($env['AI_PROVIDER'] ?? 'openai-compatible'));
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function complete(AiRequest $request): AiResult
    {
        if ($request->isTooLarge()) {
            return AiResult::unavailable('The request was longer than the configured limit.', $this->providerName);
        }
        if (!function_exists('curl_init')) {
            return AiResult::unavailable('The cURL extension is not available.', $this->providerName);
        }

        $started = (int) round(microtime(true) * 1000);
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            'max_tokens' => max(64, min(2000, $request->maxTokens)),
            'temperature' => max(0.0, min(1.0, $request->temperature)),
        ];
        if ($request->jsonShape !== null) {
            // Widely supported structured-output switch. If a provider rejects
            // it the call fails safely and the deterministic path is used.
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $handle = curl_init($this->baseUrl . '/chat/completions');
        if ($handle === false) {
            return AiResult::unavailable('Could not start the request.', $this->providerName);
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $failure = curl_error($handle);
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }
        $latency = (int) round(microtime(true) * 1000) - $started;

        if ($body === false || $status < 200 || $status >= 300) {
            $reason = $body === false ? ('Network error: ' . $failure) : ('HTTP ' . $status);
            return new AiResult(false, '', [], $this->providerName, $this->model, 0, 0, $latency, $reason);
        }

        $decoded = json_decode((string) $body, true);
        $text = is_array($decoded) ? (string) ($decoded['choices'][0]['message']['content'] ?? '') : '';
        if (trim($text) === '') {
            return new AiResult(false, '', [], $this->providerName, $this->model, 0, 0, $latency, 'The provider returned an empty answer.');
        }
        $json = [];
        if ($request->jsonShape !== null || str_starts_with(ltrim($text), '{')) {
            $candidate = json_decode($text, true);
            if (is_array($candidate)) {
                $json = $candidate;
            }
        }
        return new AiResult(
            true,
            trim($text),
            $json,
            $this->providerName,
            $this->model,
            (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            (int) ($decoded['usage']['completion_tokens'] ?? 0),
            $latency,
        );
    }
}
