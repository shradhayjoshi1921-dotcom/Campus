<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * The provider used when no credentials are configured. It never calls out and
 * never invents text: callers see `ok = false` and use their deterministic path.
 */
final class NullProvider implements AiProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function complete(AiRequest $request): AiResult
    {
        return AiResult::unavailable('No AI provider is configured.');
    }
}
