<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('agent:account-operator {email} {--revoke}')]
#[Description('Grant provider account assignment to an existing verified admin, or revoke it from any existing user')]
class ManageAccountOperator extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $revoke = (bool) $this->option('revoke');

        try {
            $userId = DB::transaction(function () use ($email, $revoke): int {
                $user = User::query()->where('email', $email)->lockForUpdate()->first();

                // Database collations may match differently cased email addresses.
                if ($user === null || $user->email !== $email
                    || (! $revoke && (! $user->hasVerifiedEmail() || ! $user->isAdmin()))) {
                    throw new RuntimeException('Ineligible account.');
                }

                $user->can_assign_provider_accounts = ! $revoke;
                $user->save();

                return $user->id;
            });
        } catch (Throwable) {
            $this->error('Permission update failed; no changes were saved. Use an exact existing email; grants require a verified admin.');

            return self::FAILURE;
        }

        Log::info('Provider account assignment permission changed.', [
            'user_id' => $userId,
            'operation' => $revoke ? 'revoke' : 'grant',
        ]);

        $this->info($revoke ? 'Provider account assignment permission revoked.' : 'Provider account assignment permission granted.');

        return self::SUCCESS;
    }
}
