<?php

namespace App\Workspace\Data;

final readonly class WorkspaceReadResult
{
    /**
     * @param  list<array{provider: string, type: string, label: string, value: string}>  $sources
     */
    public function __construct(
        public string $provider,
        public string $summary,
        public array $sources,
    ) {}
}
