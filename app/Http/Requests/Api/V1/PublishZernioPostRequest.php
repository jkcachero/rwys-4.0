<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
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
            'images' => ['sometimes', 'array', 'min:1', 'max:2'],
            'images.*' => [
                'required',
                File::types(['jpg', 'jpeg'])->max(2048),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $contents = file_get_contents($value->getRealPath());
                    $dimensions = @getimagesize($value->getRealPath());

                    if (! is_string($contents)
                        || ! str_starts_with($contents, "\xFF\xD8")
                        || ! str_ends_with($contents, "\xFF\xD9")
                        || $dimensions === false
                        || ($dimensions['mime'] ?? null) !== 'image/jpeg') {
                        $fail('The image must be a complete JPEG image.');

                        return;
                    }

                    [$width, $height] = $dimensions;
                    $ratio = $width / $height;

                    if ($width > 4096 || $height > 4096 || ($width * $height) > 16_000_000 || $ratio < 0.5625 || $ratio > 1.91) {
                        $fail('The image dimensions or aspect ratio are not supported.');
                    }
                },
            ],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff($this->submittedFields, ['content', 'images']) as $field) {
                $validator->errors()->add($field, "The {$field} field is not allowed.");
            }
        }];
    }
}
