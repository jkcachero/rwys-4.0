<?php

namespace App\Workspace\Services;

use App\Workspace\Contracts\WorkspaceAnalyst;

use function Laravel\Ai\agent;

final class LaravelAiWorkspaceAnalyst implements WorkspaceAnalyst
{
    public function analyze(string $question, array $sources): string
    {
        $response = agent(
            instructions: 'Analyze only the supplied provider sources. Do not invent accounts, posts, metrics, or actions. State when the sources do not answer the question.',
        )->prompt(json_encode([
            'question' => $question,
            'sources' => $sources,
        ], JSON_THROW_ON_ERROR));

        return $response->text;
    }
}
