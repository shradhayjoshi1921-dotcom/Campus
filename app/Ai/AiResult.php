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
        public readonly bool $ok,
        public readonly string $text = '',
        public readonly array $json = [],
        public readonly string $provider = 'none',
        public readonly string $model = '',
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly string $error = '',
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
