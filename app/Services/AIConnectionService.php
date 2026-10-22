<?php

namespace App\Services;

use App\Models\AIConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AIConnectionService
{
    /** @return array<int, array<string, mixed>> */
    public function listFor(User $user): array
    {
        return $user->aiConnections()->latest()->get([
            'id', 'provider', 'label', 'auth_mode', 'model', 'status', 'last_validated_at',
        ])->map(fn (AIConnection $connection): array => $connection->redacted())->all();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): AIConnection
    {
        $apiKey = trim((string) $data['apiKey']);

        if ($apiKey === '') {
            throw ValidationException::withMessages(['apiKey' => 'An API key is required.']);
        }

        return DB::transaction(fn (): AIConnection => $user->aiConnections()->updateOrCreate(
            ['provider' => $data['provider'], 'label' => $data['label']],
            [
                'auth_mode' => $data['authMode'],
                'credentials' => ['api_key' => $apiKey],
                'model' => $data['model'] ?? null,
                'status' => 'configured',
                'last_validated_at' => null,
            ],
        ));
    }

    public function revoke(User $user, AIConnection $connection): void
    {
        abort_unless($connection->user_id === $user->id, 404);
        $connection->update(['status' => 'revoked', 'credentials' => []]);
    }
}
