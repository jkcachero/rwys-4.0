<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client_account_id' => $this->client_account_id,
            'capability' => $this->capability,
            'state' => $this->state,
            'provider_post_id' => $this->provider_post_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'last_reconciled_at' => $this->last_reconciled_at?->toISOString(),
        ];
    }
}
