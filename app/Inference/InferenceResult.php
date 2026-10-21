<?php

namespace App\Inference;

final readonly class InferenceResult
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $text,
        public string $correlationId,
    ) {}
}
