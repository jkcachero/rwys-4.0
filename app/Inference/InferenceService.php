<?php

namespace App\Inference;

use App\Models\User;

interface InferenceService
{
    public function status(User $user): array;

    public function prompt(User $user, string $provider, string $prompt): InferenceResult;
}
