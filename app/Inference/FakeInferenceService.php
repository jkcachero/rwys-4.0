<?php

namespace App\Inference;

use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

final class FakeInferenceService implements InferenceService
{
    public function __construct(private readonly ?RuntimeException $failure = null) {}

    public function status(User $user): array
    {
        return [[
            'name' => 'openai',
            'label' => 'OpenAI',
            'model' => 'fake-model',
            'status' => 'unavailable',
            'connected' => false,
            'expiresAt' => null,
            'message' => 'Simulated inference only; no live provider is connected.',
        ]];
    }

    public function models(User $user, string $provider, ?int $connectionId = null, bool $refresh = false): array
    {
        return [['slug' => 'fake-model', 'displayName' => 'Simulated model', 'thinkingLevels' => []]];
    }

    public function prompt(User $user, string $provider, string $prompt, ?int $connectionId = null, ?string $model = null, ?string $thinkingLevel = null, array $history = []): InferenceResult
    {
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        return new InferenceResult($provider, 'fake-model', 'Fake response: '.$prompt, (string) Str::uuid());
    }
}
