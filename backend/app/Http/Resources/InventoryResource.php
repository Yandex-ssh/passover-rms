<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
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

            'menu_item' => [
                'id' => $this->menuItem->id,
                'name' => $this->menuItem->name,
            ],

            'quantity' => $this->quantity,

            'low_stock_threshold' => $this->low_stock_threshold,

            'is_low_stock' =>
                $this->quantity > 0 &&
                $this->quantity <= $this->low_stock_threshold,

            'is_out_of_stock' => $this->quantity === 0,

            'last_restocked_at' =>
                $this->last_restocked_at?->toIso8601String(),

            'created_at' =>
                $this->created_at?->toIso8601String(),

            'updated_at' =>
                $this->updated_at?->toIso8601String(),
        ];
    }
}