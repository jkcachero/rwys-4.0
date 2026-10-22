<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'environment' => $this->environment,
            'platform' => $this->platform,
            'status' => $this->active ? 'mapped' : 'inactive',
            'write_enabled' => $this->active && $this->publishing_enabled
                && config('agent-api.zernio.enabled', false) === true
                && config('agent-api.zernio.publishing_enabled', false) === true
                && is_string(config('agent-api.zernio.api_key')) && preg_match('/\A[\x21-\x7e]+\z/', config('agent-api.zernio.api_key')) === 1
                && ((in_array($this->platform, ['linkedin', 'facebook', 'threads', 'bluesky'], true)
                    && in_array('zernio.posts.publish', $request->attributes->get('agent_token')?->abilities ?? [], true))
                    || ($this->platform === 'instagram'
                        && config('agent-api.zernio.instagram_scheduling_enabled', false) === true
                        && in_array('zernio.posts.schedule', $request->attributes->get('agent_token')?->abilities ?? [], true))),
        ];
    }
}
