<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * A provider turns one request into one result. Implementations must never
 * throw: a network failure becomes an AiResult with ok = false.
 */
interface AiProvider
{
    public function name(): string;

    public function complete(AiRequest $request): AiResult;
}
