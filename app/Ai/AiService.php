<?php
declare(strict_types=1);

namespace App\Ai;

use PDO;
use Throwable;

/**
 * The single door every AI-assisted feature uses.
 *
 * Responsibilities, in order:
 *   1. refuse when the feature is disabled or has no provider (callers then use
 *      their deterministic path — no feature may block on a network call);
 *   2. enforce a per-user, per-feature hourly allowance before spending money;
 *   3. send only the text the caller explicitly assembled;
 *   4. validate the answer against the shape the caller declared;
 *   5. log usage (feature, provider, model, tokens, latency, outcome — never
 *      prompt or completion text) so cost and failures are visible.
 */
final class AiService
{
    private const MAX_CHARACTERS = 12000;
    private const RATE_DEFAULTS = [
        'copilot' => 40,
        'resume' => 12,
        'roadmap' => 12,
        'interview' => 20,
        'job_description' => 20,
        'insights' => 20,
    ];

    private ?PDO $db;
    private AiProvider $provider;
    private array $settings;

    public function __construct(?PDO $db, ?AiProvider $provider = null, array $settings = [])
    {
        $this->db = $db;
        $this->provider = $provider ?? new NullProvider();
        $this->settings = $settings;
    }

    public static function fromEnvironment(?PDO $db, array $env, array $settings = []): self
    {
        $enabled = filter_var($env['AI_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $provider = $enabled ? HttpProvider::fromEnv($env) : null;
        return new self($db, $provider, $settings);
    }

    public function isConfigured(): bool
    {
        return !($this->provider instanceof NullProvider);
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /** Ask for a plain-language answer. Returns null when unavailable. */
    public function complete(AiRequest $request): ?string
    {
        $result = $this->run($request);
        return $result->isOk() ? $result->text : null;
    }

    /**
     * Ask for structured data. The answer is only returned when it is an array
     * containing every key the caller declared; anything else is discarded so
     * a malformed model answer can never reach a database write.
     */
    public function structured(AiRequest $request, array $requiredKeys): ?array
    {
        $result = $this->run($request);
        if (!$result->isOk() || $result->json === []) {
            return null;
        }
        $data = $result->json;
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                return null;
            }
        }
        return $data;
    }

    private function run(AiRequest $request): AiResult
    {
        if (!$this->isConfigured()) {
            return AiResult::unavailable('No AI provider is configured.');
        }
        if ($request->isTooLarge(self::MAX_CHARACTERS)) {
            $this->log($request, AiResult::unavailable('Request exceeded the size limit.'), false);
            return AiResult::unavailable('The request was longer than the configured limit.');
        }
        if (!$this->withinAllowance($request)) {
            $this->log($request, AiResult::unavailable('Hourly allowance reached.'), false);
            return AiResult::unavailable('The hourly AI allowance for this feature has been reached.');
        }

        $result = $this->provider->complete($request);
        $this->log($request, $result, true);
        return $result;
    }

    private function hourlyLimit(AiRequest $request): int
    {
        $override = $this->settings['ai_rate_' . $request->feature] ?? null;
        return is_numeric($override) ? max(1, (int) $override) : (self::RATE_DEFAULTS[$request->feature] ?? 20);
    }

    private function withinAllowance(AiRequest $request): bool
    {
        if ($this->db === null) {
            return true;
        }
        try {
            $statement = $this->db->prepare(
                "SELECT COUNT(*) FROM ai_usage_logs WHERE feature = ? AND user_id <=> ? AND created_at >= (NOW() - INTERVAL 1 HOUR)",
            );
            $statement->execute([$request->feature, $request->userId]);
            return (int) $statement->fetchColumn() < $this->hourlyLimit($request);
        } catch (Throwable $exception) {
            // A missing log table must not block a configured feature.
            error_log('AI allowance check failed: ' . $exception->getMessage());
            return true;
        }
    }

    private function log(AiRequest $request, AiResult $result, bool $attempted): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            $this->db->prepare(
                'INSERT INTO ai_usage_logs (user_id, feature, provider, model, prompt_tokens, completion_tokens, latency_ms, ok, error_code) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([
                $request->userId,
                mb_substr($request->feature, 0, 60),
                mb_substr($result->provider, 0, 60),
                mb_substr($result->model, 0, 120),
                $result->promptTokens,
                $result->completionTokens,
                $result->latencyMs,
                $result->isOk() ? 1 : 0,
                $result->isOk() ? null : mb_substr($result->error, 0, 190),
            ]);
        } catch (Throwable $exception) {
            error_log('AI usage log failure: ' . $exception->getMessage());
        }
    }
}
