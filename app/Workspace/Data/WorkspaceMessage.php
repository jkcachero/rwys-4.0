<?php

namespace App\Workspace\Data;

final readonly class WorkspaceMessage
{
    /**
     * @param  list<array{provider: string, type: string, label: string, value: string}>  $sources
     */
    public function __construct(
        public string $role,
        public string $content,
        public array $sources = [],
    ) {}
}
