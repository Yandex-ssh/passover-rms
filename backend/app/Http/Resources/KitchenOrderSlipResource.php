<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenOrderSlipResource extends JsonResource
{
    /**
     * Return only fields needed by the kitchen slip.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->order;

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'printed_at' => $this->printed_at?->toIso8601String(),
            'print_count' => $this->print_count,
            'order_number' => $order->order_number,
            'order_time' => $order->submitted_at?->toIso8601String(),
            'table_number' => $order->tableSession->restaurantTable->table_number,
            'customer_note' => $order->customer_note,
            'items' => $order->items->map(fn ($item) => [
                'menu_item_name' => $item->menuItem->name,
                'quantity' => $item->quantity,
                'special_instruction' => $item->special_instruction,
            ])->values(),
        ];
    }
}
