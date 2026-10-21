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
            'status' => 'available',
            'message' => 'Ready for authenticated inference.',
        ]];
    }

    public function prompt(User $user, string $provider, string $prompt): InferenceResult
    {
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        return new InferenceResult($provider, 'fake-model', 'Fake response: '.$prompt, (string) Str::uuid());
    }
}
