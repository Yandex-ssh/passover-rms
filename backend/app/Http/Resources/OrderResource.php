<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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

            'order_number' => $this->order_number,

            'tracking_token' => $this->tracking_token,

            'status' => $this->status,

            'subtotal' => $this->subtotal,

            'customer_note' => $this->customer_note,

            'submitted_at' =>
                $this->submitted_at?->toIso8601String(),

            'confirmed_at' =>
                $this->confirmed_at?->toIso8601String(),

            'table_session' =>
                $this->whenLoaded('tableSession', function () {
                    return [
                        'id' => $this->tableSession->id,
                        'status' => $this->tableSession->status,

                        'table' => $this->tableSession->relationLoaded('restaurantTable')
                            ? [
                                'id' => $this->tableSession->restaurantTable->id,
                                'table_number' => $this->tableSession->restaurantTable->table_number,
                            ]
                            : null,
                    ];
                }),

            'dining_transaction' =>
                $this->whenLoaded('diningTransaction', function () {
                    if ($this->diningTransaction === null) {
                        return null;
                    }

                    return [
                        'id' => $this->diningTransaction->id,
                        'transaction_number' => $this->diningTransaction->transaction_number,
                        'status' => $this->diningTransaction->status,
                    ];
                }),

            'items' => OrderItemResource::collection(
                $this->whenLoaded('items')
            ),

            'created_at' =>
                $this->created_at?->toIso8601String(),

            'updated_at' =>
                $this->updated_at?->toIso8601String(),
        ];
    }
}
