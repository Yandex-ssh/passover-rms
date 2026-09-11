<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantTableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'qr_token'     => $this->qr_token,
            'table_number' => $this->table_number,
            'capacity'     => $this->capacity,
            'status'       => $this->status,
            'active_session' => $this->whenLoaded('activeSession', fn () => $this->activeSession ? [
                'id' => $this->activeSession->id,
                'opened_at' => $this->activeSession->opened_at?->toIso8601String(),
            ] : null),
            'is_available' => $this->status === 'available', // Useful helper flag for frontends
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
