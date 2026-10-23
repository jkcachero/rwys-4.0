<?php

namespace App\Http\Requests\Settings;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IssueAgentTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client
            && $this->user()?->hasVerifiedEmail()
            && $client->is_active
            && $client->hasActiveOwner($this->user());
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'allowed_account_ids' => ['present', 'array', 'max:300'],
            'allowed_account_ids.*' => ['required', 'uuid', 'distinct'],
            'expires_at' => [
                'required',
                'date',
                'after:now',
                'before_or_equal:'.now()->addDays(90)->toDateTimeString(),
            ],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $client = $this->route('client');
            $accountIds = array_values(array_unique($this->input('allowed_account_ids', [])));

            if (! $client instanceof Client || $accountIds === []) {
                return;
            }

            $permissions = $client->accountPermissions()
                ->where('is_active', true)
                ->where('can_read', true)
                ->whereHas('account', fn ($query) => $query
                    ->where('is_active', true)
                    ->whereIn('public_id', $accountIds))
                ->with('account:id,public_id')
                ->get();

            if ($permissions->count() !== count($accountIds)) {
                $validator->errors()->add(
                    'allowed_account_ids',
                    'Every selected account must be active and readable for this Client.',
                );
            }

        }];
    }
}
