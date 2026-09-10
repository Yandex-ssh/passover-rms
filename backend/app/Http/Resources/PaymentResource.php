<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource['payment'];
        $transaction = $this->resource['transaction'];

        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'transaction_number' => $transaction->transaction_number,
            'payment_method' => $payment->payment_method,
            'amount' => $payment->amount,
            'amount_received' => $payment->amount_received,
            'change_amount' => $payment->change_amount,
            'reference_number' => $payment->reference_number,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'receipt' => $this->resource['receipt']
                ? [
                    'id' => $this->resource['receipt']->id,
                    'receipt_number' => $this->resource['receipt']->receipt_number,
                    'status' => $this->resource['receipt']->status,
                ]
                : null,
            'transaction' => [
                'total_amount' => $this->resource['total_amount'],
                'paid_amount' => $this->resource['paid_amount'],
                'remaining_balance' => $this->resource['remaining_balance'],
                'status' => $transaction->status,
                'paid_at' => $transaction->paid_at?->toIso8601String(),
            ],
        ];
    }
}
