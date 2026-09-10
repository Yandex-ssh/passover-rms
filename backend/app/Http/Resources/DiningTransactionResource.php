<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiningTransactionResource extends JsonResource
{
    /**
     * Transform a calculated dining transaction bill.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transaction = $this->resource['transaction'];
        $orders = $this->resource['orders'];

        return [
            'id' => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'status' => $transaction->status,
            'opened_at' => $transaction->opened_at?->toIso8601String(),
            'table_session' => [
                'id' => $transaction->tableSession->id,
                'status' => $transaction->tableSession->status,
            ],
            'table' => [
                'id' => $transaction->tableSession->restaurantTable->id,
                'table_number' => $transaction->tableSession->restaurantTable->table_number,
            ],
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'submitted_at' => $order->submitted_at?->toIso8601String(),
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
                'customer_note' => $order->customer_note,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->menuItem->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                ])->values(),
            ])->values(),
            'subtotal' => $this->resource['subtotal'],
            'total_amount' => $this->resource['total_amount'],
            'paid_amount' => $this->resource['paid_amount'],
            'remaining_balance' => $this->resource['remaining_balance'],
            'paid_at' => $transaction->paid_at?->toIso8601String(),
        ];
    }
}
