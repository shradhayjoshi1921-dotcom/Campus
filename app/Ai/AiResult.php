<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * The outcome of one attempt. `ok === false` never throws at the call site:
 * every feature has a deterministic path that runs when this is not ok.
 */
final class AiResult
{
    public function __construct(
        public bool $ok,
        public string $text = '',
        public array $json = [],
        public string $provider = 'none',
        public string $model = '',
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $latencyMs = 0,
        public string $error = '',
    ) {
    }

    public static function unavailable(string $reason, string $provider = 'none'): self
    {
        return new self(false, '', [], $provider, '', 0, 0, 0, $reason);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }
}
