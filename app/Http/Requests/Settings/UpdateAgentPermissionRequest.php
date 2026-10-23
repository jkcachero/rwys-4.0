<?php

namespace App\Http\Requests\Settings;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAgentPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');
        $permission = $this->route('permission');

        return $client instanceof Client
            && $permission instanceof ClientAccountPermission
            && $permission->client_id === $client->id
            && $this->user()?->hasVerifiedEmail()
            && $client->is_active
            && $client->hasActiveOwner($this->user());
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'can_read' => ['required', 'boolean'],
            'can_publish' => ['required', 'boolean'],
            'can_schedule' => ['required', 'boolean'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('can_read')
                && ($this->boolean('can_publish') || $this->boolean('can_schedule'))) {
                $validator->errors()->add('can_read', 'Publish and schedule permissions require read access.');
            }
        }];
    }
}
