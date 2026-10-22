<?php

namespace App\Http\Requests\Settings;

use App\Models\PersonalAccessToken;
use Illuminate\Validation\Validator;

class RotateAgentTokenRequest extends IssueAgentTokenRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');
        $token = $this->route('token');

        return parent::authorize()
            && $token instanceof PersonalAccessToken
            && $token->client_id === $client->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(parent::rules())->except('expires_at')->all();
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $token = $this->route('token');

                if (! $token instanceof PersonalAccessToken) {
                    return;
                }

                if (array_diff($this->input('abilities', []), $token->abilities ?? []) !== []) {
                    $validator->errors()->add('abilities', 'Token rotation cannot add abilities.');
                }

                $currentAccountIds = $token->allowed_account_ids ?? [];

                $requestedAccountIds = $this->input('allowed_account_ids', []);

                if ($currentAccountIds !== []
                    && ($requestedAccountIds === [] || array_diff($requestedAccountIds, $currentAccountIds) !== [])) {
                    $validator->errors()->add('allowed_account_ids', 'Token rotation cannot add account access.');
                }
            },
        ];
    }
}
