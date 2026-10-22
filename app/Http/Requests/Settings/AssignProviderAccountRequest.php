<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class AssignProviderAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('assign-provider-accounts');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer:strict', 'min:1'],
            'profile_id' => ['required', 'string', 'regex:/\A[a-f0-9]{24}\z/'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->getQueryString() !== null || $this->allFiles() !== []) {
                $validator->errors()->add('request', 'Query strings and files are not accepted.');
            }
            foreach (array_diff(array_keys($this->all()), ['client_id', 'profile_id']) as $field) {
                $validator->errors()->add($field, 'Unknown field.');
            }
        }];
    }
}
