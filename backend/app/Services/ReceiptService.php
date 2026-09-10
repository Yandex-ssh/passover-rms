<?php

namespace App\Services;

use App\Models\DiningTransaction;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    public function __construct(
        private readonly BillingService $billingService
    ) {}

    /**
     * Generate or return the permanent receipt for a fully paid transaction.
     */
    public function generateForTransaction(
        DiningTransaction $transaction
    ): Receipt {
        return DB::transaction(function () use ($transaction): Receipt {
            $lockedTransaction = DiningTransaction::query()
                ->whereKey($transaction->id)
                ->with('receipt')
                ->lockForUpdate()
                ->firstOrFail();

            $bill = $this->billingService->calculateBill($lockedTransaction);
            $payments = $lockedTransaction->payments()
                ->where('status', 'completed')
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get();

            if (
                $lockedTransaction->status !== 'paid' ||
                ! $lockedTransaction->paid_at ||
                $payments->isEmpty() ||
                $bill['remaining_balance'] !== '0.00' ||
                $bill['paid_amount'] !== $bill['total_amount']
            ) {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Only a fully and consistently paid transaction can receive a receipt.',
                    ],
                ]);
            }

            if ($lockedTransaction->receipt) {
                return $lockedTransaction->receipt;
            }

            do {
                $receiptNumber =
                    'RCT-'.
                    now()->format('Ymd').
                    '-'.
                    Str::upper(Str::random(6));
            } while (Receipt::query()->where('receipt_number', $receiptNumber)->exists());

            return Receipt::create([
                'dining_transaction_id' => $lockedTransaction->id,
                'receipt_number' => $receiptNumber,
                'status' => 'generated',
                'snapshot' => $this->buildSnapshot(
                    $lockedTransaction,
                    $bill,
                    $payments
                ),
                'generated_at' => now(),
                'print_count' => 0,
            ]);
        });
    }

    /**
     * Record one backend receipt print operation.
     */
    public function markPrinted(Receipt $receipt): Receipt
    {
        return DB::transaction(function () use ($receipt): Receipt {
            $lockedReceipt = Receipt::query()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedReceipt->update([
                'status' => 'printed',
                'printed_at' => now(),
                'print_count' => $lockedReceipt->print_count + 1,
            ]);

            return $lockedReceipt->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(
        DiningTransaction $transaction,
        array $bill,
        $payments
    ): array {
        $items = $bill['orders']->flatMap(
            fn ($order) => $order->items->map(fn ($item) => [
                'order_number' => $order->order_number,
                'name' => $item->menuItem->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])
        )->values();

        return [
            'restaurant_name' => 'Pass-over Cafe',
            'transaction_number' => $transaction->transaction_number,
            'table_number' => $transaction->tableSession->restaurantTable->table_number,
            'paid_at' => $transaction->paid_at?->toIso8601String(),
            'items' => $items->all(),
            'subtotal' => $bill['subtotal'],
            'total_amount' => $bill['total_amount'],
            'paid_amount' => $bill['paid_amount'],
            'remaining_balance' => $bill['remaining_balance'],
            'payments' => $payments->map(fn ($payment) => [
                'payment_number' => $payment->payment_number,
                'payment_method' => $payment->payment_method,
                'amount' => $payment->amount,
                'amount_received' => $payment->amount_received,
                'change_amount' => $payment->change_amount,
                'reference_number' => $payment->reference_number,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
