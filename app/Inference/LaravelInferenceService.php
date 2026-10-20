<?php

namespace App\Inference;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use RuntimeException;

final class LaravelInferenceService implements InferenceService
{
    public function status(): array
    {
        return collect(config('workspace.providers'))
            ->map(fn (array $provider, string $key): array => [
                'name' => $key,
                'label' => $provider['label'],
                'model' => $provider['model'],
                'status' => filled(config("ai.providers.{$key}.key")) ? 'available' : 'unavailable',
                'message' => filled(config("ai.providers.{$key}.key"))
                    ? 'Ready for authenticated inference.'
                    : 'Provider credentials are not configured.',
            ])
            ->values()
            ->all();
    }

    public function prompt(string $provider, string $prompt): InferenceResult
    {
        $configuration = config("workspace.providers.{$provider}");

        if (! is_array($configuration) || ! filled(config("ai.providers.{$provider}.key"))) {
            throw new RuntimeException('The selected provider is unavailable.');
        }

        $agent = new class implements Agent {
            use Promptable;

            public function instructions(): string
            {
                return 'Answer the user request clearly and concisely. Do not perform external actions.';
            }
        };

        $response = $agent->prompt($prompt, provider: $provider, model: $configuration['model']);

        return new InferenceResult($provider, $configuration['model'], $response->text, (string) Str::uuid());
    }
}
