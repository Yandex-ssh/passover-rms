<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
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

            'menu_item' => $this->whenLoaded('menuItem', function () {
                return [
                    'id' => $this->menuItem->id,
                    'name' => $this->menuItem->name,
                ];
            }),

            'quantity' => $this->quantity,

            'unit_price' => $this->unit_price,

            'subtotal' => $this->subtotal,

            'special_instruction' => $this->special_instruction,
        ];
    }
}