<?php

namespace App\Inference;

use App\Models\User;

interface InferenceService
{
    public function status(User $user): array;

    /** @return list<array{slug: string, displayName: string, thinkingLevels: list<string>}> */
    public function models(User $user, string $provider, ?int $connectionId = null, bool $refresh = false): array;

    /** @param list<array{role: string, content: string}> $history Server-trusted conversation messages only. */
    public function prompt(User $user, string $provider, string $prompt, ?int $connectionId = null, ?string $model = null, ?string $thinkingLevel = null, array $history = []): InferenceResult;
}
