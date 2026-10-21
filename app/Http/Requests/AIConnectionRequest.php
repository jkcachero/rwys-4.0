<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AIConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(['openai', 'claude'])],
            'label' => ['required', 'string', 'max:100'],
            'authMode' => ['required', 'string', Rule::in(['api_key'])],
            'apiKey' => ['required', 'string', 'min:10', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
        ];
    }
}
