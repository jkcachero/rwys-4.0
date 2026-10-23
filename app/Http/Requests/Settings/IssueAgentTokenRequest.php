<?php

namespace App\Http\Requests\Settings;

use App\Enums\AgentAbility;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(AgentAbility::enabled())],
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

            $abilities = $this->input('abilities', []);

            if (in_array(AgentAbility::PostsPublish->value, $abilities, true)
                && ! $permissions->contains('can_publish', true)) {
                $validator->errors()->add('abilities', 'Publishing requires a selected account with publish permission.');
            }

            if (in_array(AgentAbility::PostsSchedule->value, $abilities, true)
                && ! $permissions->contains('can_schedule', true)) {
                $validator->errors()->add('abilities', 'Scheduling requires a selected account with schedule permission.');
            }
        }];
    }
}
