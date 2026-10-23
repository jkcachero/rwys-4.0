<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublishZernioPostRequest extends FormRequest
{
    /** @var list<string> */
    private array $submittedFields = [];

    protected function prepareForValidation(): void
    {
        $this->submittedFields = array_keys($this->all());
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:3000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff($this->submittedFields, ['content']) as $field) {
                $validator->errors()->add($field, "The {$field} field is not allowed.");
            }
        }];
    }
}
