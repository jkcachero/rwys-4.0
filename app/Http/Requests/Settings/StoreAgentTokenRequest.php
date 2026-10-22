<?php

namespace App\Http\Requests\Settings;

use App\Services\AgentTokenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAgentTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer:strict', 'min:1'],
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'required', 'array', 'list', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct:strict', Rule::in(AgentTokenService::ABILITIES)],
            'allowed_account_ids' => ['sometimes', 'nullable', 'array', 'list', 'min:1'],
            'allowed_account_ids.*' => ['required', 'integer:strict', 'min:1', 'distinct'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['client_id', 'name', 'abilities', 'allowed_account_ids']) as $field) {
                $validator->errors()->add($field, 'Unknown field.');
            }
        }];
    }
}
