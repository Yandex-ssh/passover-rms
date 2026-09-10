<?php

namespace App\Services;

use App\Models\DiningTransaction;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    /**
     * Attach a confirmed order to its table session's current open transaction.
     */
    public function attachConfirmedOrder(Order $order): DiningTransaction
    {
        return DB::transaction(function () use ($order): DiningTransaction {
            $currentOrder = Order::query()->whereKey($order->id)->firstOrFail();

            if ($currentOrder->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'order' => ['Only confirmed orders can join a dining transaction.'],
                ]);
            }

            if ($currentOrder->dining_transaction_id) {
                return DiningTransaction::query()
                    ->findOrFail($currentOrder->dining_transaction_id);
            }

            TableSession::query()
                ->whereKey($currentOrder->table_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = DiningTransaction::query()
                ->where('table_session_id', $currentOrder->table_session_id)
                ->whereIn('status', ['open', 'partially_paid'])
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                $transaction = DiningTransaction::create([
                    'table_session_id' => $currentOrder->table_session_id,
                    'transaction_number' => $this->generateTransactionNumber(),
                    'status' => 'open',
                    'opened_at' => now(),
                ]);
            }

            $currentOrder->update([
                'dining_transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }


    /**
     * Build a read-only combined bill from accepted historical order items.
     *
     * @return array<string, mixed>
     */
    public function calculateBill(DiningTransaction $transaction): array
    {
        $transaction->load('tableSession.restaurantTable');

        $orders = $transaction->orders()
            ->whereIn('status', ['confirmed', 'preparing', 'completed'])
            ->with('items.menuItem')
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get();

        $subtotalInCents = 0;

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $subtotalInCents += $this->decimalToCents($item->subtotal);
            }
        }

        $subtotal = $this->formatCents($subtotalInCents);
        $paidInCents = 0;

        foreach (
            $transaction->payments()
                ->where('status', 'completed')
                ->get(['amount']) as $payment
        ) {
            $paidInCents += $this->decimalToCents($payment->amount);
        }

        return [
            'transaction' => $transaction,
            'orders' => $orders,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'paid_amount' => $this->formatCents($paidInCents),
            'remaining_balance' => $this->formatCents(
                max($subtotalInCents - $paidInCents, 0)
            ),
        ];
    }

    private function decimalToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function formatCents(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad(
            (string) ($amount % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }

    private function generateTransactionNumber(): string
    {
        do {
            $transactionNumber =
                'TXN-' .
                now()->format('Ymd') .
                '-' .
                Str::upper(Str::random(6));
        } while (
            DiningTransaction::query()
                ->where('transaction_number', $transactionNumber)
                ->exists()
        );

        return $transactionNumber;
    }
}
