<?php

namespace App\Workspace\Services;

use App\AdKit\Services\AdKitReadService;
use App\Workspace\Data\WorkspaceReadResult;
use App\Zernio\Services\ZernioReadService;
use InvalidArgumentException;

final readonly class WorkspaceReadService
{
    public function __construct(
        private AdKitReadService $adKit,
        private ZernioReadService $zernio,
    ) {}

    public function answer(string $provider, string $scopeId, string $question): WorkspaceReadResult
    {
        if ($question === '') {
            throw new InvalidArgumentException('A read question is required.');
        }

        $accounts = match ($provider) {
            'adkit' => $this->adKit->accounts($scopeId),
            'zernio' => $this->zernio->accounts($scopeId),
            default => throw new InvalidArgumentException('The requested provider is not available.'),
        };

        $sources = array_map(static fn (object $account): array => [
            'provider' => $provider,
            'type' => 'account',
            'label' => $account->name,
            'value' => $account->status,
        ], $accounts);

        return new WorkspaceReadResult(
            $provider,
            sprintf('%s accounts available: %d. Request analyzed: %s', ucfirst($provider), count($accounts), $question),
            $sources,
        );
    }
}
