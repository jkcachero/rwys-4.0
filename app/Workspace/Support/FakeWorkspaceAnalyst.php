<?php

namespace App\Workspace\Support;

use App\Workspace\Contracts\WorkspaceAnalyst;

final class FakeWorkspaceAnalyst implements WorkspaceAnalyst
{
    public function analyze(string $question, array $sources): string
    {
        return sprintf('Accounts available: %d. Request analyzed: %s', count($sources), $question);
    }
}
