<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Resolves the scoring configuration once per request.
 *
 * Values come from `config/scoring.php`; a site can override any weight through
 * `system_settings` (key `scoring.weights.skills`, value `0.5`) without editing
 * code. Unknown or malformed overrides are ignored rather than trusted.
 */
final class ScoringModel
{
    private array $config;
    private array $settings;

    public function __construct(array $config = [], array $settings = [])
    {
        $file = dirname(__DIR__, 2) . '/config/scoring.php';
        $defaults = is_file($file) ? require $file : [];
        $this->config = $config ?: $defaults;
        $this->settings = $settings;
    }

    public function weights(): array
    {
        $weights = $this->config['weights'] ?? [];
        foreach ($weights as $key => $default) {
            $override = $this->settings['scoring.weights.' . $key] ?? null;
            if (is_numeric($override) && (float) $override >= 0 && (float) $override <= 1) {
                $weights[$key] = (float) $override;
            }
        }
        $total = array_sum($weights);
        if ($total <= 0) {
            return $this->config['weights'];
        }
        // Normalise so a partial override cannot push the sum past 1.0.
        return array_map(static fn ($value) => $value / $total, $weights);
    }

    public function fallbackWeights(): array
    {
        return $this->config['fallback_weights'] ?? ['academic' => 0.55, 'role' => 0.45];
    }

    public function evidenceLevels(): array
    {
        return $this->config['evidence_levels'] ?? ['none' => 0.0, 'claimed' => 0.25];
    }

    public function evidenceValue(string $level): float
    {
        return (float) ($this->evidenceLevels()[$level] ?? 0.0);
    }

    public function demonstratedThreshold(): float
    {
        return (float) ($this->config['demonstrated_threshold'] ?? 0.6);
    }

    public function readinessWeights(): array
    {
        return $this->config['readiness_weights'] ?? [];
    }

    public function band(float $score): string
    {
        foreach ($this->config['bands'] ?? [] as $band) {
            if ($score >= (float) $band['min']) {
                return (string) $band['label'];
            }
        }
        return 'Early alignment';
    }

    /** Human-readable arithmetic for the UI and for stored explanations. */
    public function explainComponent(string $component, ?float $value, float $weight): string
    {
        $label = [
            'skills' => 'Required skills matched',
            'evidence' => 'Strength of evidence',
            'academic' => 'Academic fit',
            'role' => 'Role alignment',
        ][$component] ?? ucfirst($component);
        if ($value === null) {
            return $label . ': no data, weight redistributed.';
        }
        return sprintf('%s: %.1f%% × %.0f%% weight = %.1f points.', $label, $value, $weight * 100, $value * $weight);
    }
}
