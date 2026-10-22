<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * One validated request to a language model.
 *
 * The payload is deliberately narrow: a feature name for rate limiting and
 * logging, the task instruction, and only the user text that feature is
 * allowed to send. Nothing is serialised from a database row wholesale.
 */
final class AiRequest
{
    public function __construct(
        public readonly string $feature,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly int $maxTokens = 700,
        public readonly float $temperature = 0.2,
        public readonly ?array $jsonShape = null,
        public readonly ?int $userId = null,
    ) {
    }

    public function isTooLarge(int $maxCharacters = 12000): bool
    {
        return mb_strlen($this->systemPrompt) + mb_strlen($this->userPrompt) > $maxCharacters;
    }
}
