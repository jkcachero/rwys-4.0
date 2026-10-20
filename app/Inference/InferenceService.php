<?php

namespace App\Inference;

interface InferenceService
{
    public function status(): array;

    public function prompt(string $provider, string $prompt): InferenceResult;
}
