<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ScheduleInstagramPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('agent_token');
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [...$this->request->all(), ...$this->allFiles(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:2200'],
            'scheduled_for' => ['required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'image' => ['required', 'file', 'image', 'mimetypes:image/jpeg', 'max:2048'],
            'idempotency_key' => ['required', 'string', 'regex:/\A[\x21-\x7e]{1,255}\z/'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (preg_match('/\Amultipart\/form-data(?:;|\z)/i', $this->header('Content-Type', '')) !== 1 || $this->query->all() !== []) {
                $validator->errors()->add('request', 'Only multipart form data without query fields is accepted.');
            }
            if (array_diff(array_keys($this->request->all()), ['content', 'scheduled_for']) !== []
                || array_diff(array_keys($this->allFiles()), ['image']) !== []) {
                $validator->errors()->add('request', 'Unknown field or file.');
            }
        }];
    }
}
