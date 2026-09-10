<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot;

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'printed_at' => $this->printed_at?->toIso8601String(),
            'print_count' => $this->print_count,
            'restaurant_name' => $snapshot['restaurant_name'],
            'transaction_number' => $snapshot['transaction_number'],
            'table_number' => $snapshot['table_number'],
            'paid_at' => $snapshot['paid_at'],
            'items' => $snapshot['items'],
            'subtotal' => $snapshot['subtotal'],
            'total_amount' => $snapshot['total_amount'],
            'paid_amount' => $snapshot['paid_amount'],
            'remaining_balance' => $snapshot['remaining_balance'],
            'payments' => $snapshot['payments'],
        ];
    }
}
