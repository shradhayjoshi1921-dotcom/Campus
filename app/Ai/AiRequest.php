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
        public string $feature,
        public string $systemPrompt,
        public string $userPrompt,
        public int $maxTokens = 700,
        public float $temperature = 0.2,
        public ?array $jsonShape = null,
        public ?int $userId = null,
    ) {
    }

    public function isTooLarge(int $maxCharacters = 12000): bool
    {
        return mb_strlen($this->systemPrompt) + mb_strlen($this->userPrompt) > $maxCharacters;
    }
}
