<?php

namespace App\Workspace\Contracts;

interface WorkspaceAnalyst
{
    /**
     * @param  list<array{provider: string, type: string, label: string, value: string}>  $sources
     */
    public function analyze(string $question, array $sources): string;
}
