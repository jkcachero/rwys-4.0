<?php

namespace App\Http\Requests\Settings;

use App\Models\Client;
use App\Services\AdKitAccountAssignmentService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class AssignAdKitAccountRequest extends FormRequest
{
    public function authorize(AdKitAccountAssignmentService $assignments): bool
    {
        $client = $this->route('client');
        if ($this->user() === null || ! $client instanceof Client) {
            return false;
        }
        $assignments->assertAuthority($this->user(), $client);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['ad_account_id' => ['required', 'string', 'max:255', 'regex:/\Aact_[0-9]+\z/']];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['ad_account_id']) !== []) {
                $validator->errors()->add('ad_account_id', 'Only an AdKit ad account selection is accepted.');
            }
        }];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new ValidationException($validator, to_route('settings.agent-access.edit', ['client' => $this->route('client')->public_id])
            ->withErrors($validator));
    }
}
