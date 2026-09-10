<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
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

            'category_id' => $this->category_id,

            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ],

            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'image' => $this->image,
            'is_available' => $this->is_available,

            'inventory' => $this->whenLoaded('inventory', function () {
                return [
                    'id' => $this->inventory->id,
                    'quantity' => $this->inventory->quantity,
                    'low_stock_threshold' => $this->inventory->low_stock_threshold,
                    'last_restocked_at' =>
                        $this->inventory->last_restocked_at?->toIso8601String(),
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
