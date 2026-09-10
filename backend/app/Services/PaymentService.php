<?php

namespace App\Services;

use App\Models\DiningTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly ReceiptService $receiptService
    ) {}

    /**
     * Record a Cash payment against the current locked balance.
     *
     * @return array<string, mixed>
     */
    public function recordCashPayment(
        DiningTransaction $transaction,
        array $data
    ): array {
        return DB::transaction(function () use ($transaction, $data): array {
            $lockedTransaction = $this->lockTransaction($transaction);
            $receivedInCents = $this->decimalToCents($data['amount_received']);
            $existing = $this->findIdempotentPayment(
                $lockedTransaction,
                $data['idempotency_key']
            );

            if ($existing) {
                if (
                    $existing->payment_method !== 'cash' ||
                    $this->decimalToCents($existing->amount_received) !== $receivedInCents
                ) {
                    throw new ConflictHttpException(
                        'The idempotency key was already used with different payment data.'
                    );
                }

                $receipt = $lockedTransaction->status === 'paid'
                    ? $this->receiptService->generateForTransaction($lockedTransaction)
                    : null;

                return $this->paymentResult(
                    $existing,
                    $lockedTransaction,
                    false,
                    $receipt
                );
            }

            $this->ensurePayable($lockedTransaction);
            $settlement = $this->settlement($lockedTransaction);
            $remainingInCents = $this->decimalToCents($settlement['remaining_balance']);
            $appliedInCents = min($receivedInCents, $remainingInCents);
            $changeInCents = max($receivedInCents - $remainingInCents, 0);

            $payment = Payment::create([
                'dining_transaction_id' => $lockedTransaction->id,
                'payment_number' => $this->generatePaymentNumber(),
                'payment_method' => 'cash',
                'amount' => $this->formatCents($appliedInCents),
                'amount_received' => $this->formatCents($receivedInCents),
                'change_amount' => $this->formatCents($changeInCents),
                'reference_number' => null,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'completed',
                'paid_at' => now(),
            ]);

            $this->updateTransactionSettlement($lockedTransaction);
            $lockedTransaction = $lockedTransaction->fresh();
            $receipt = $lockedTransaction->status === 'paid'
                ? $this->receiptService->generateForTransaction($lockedTransaction)
                : null;

            return $this->paymentResult(
                $payment,
                $lockedTransaction,
                true,
                $receipt
            );
        });
    }

    /**
     * Record a manually verified GCash payment.
     *
     * @return array<string, mixed>
     */
    public function recordGcashPayment(
        DiningTransaction $transaction,
        array $data
    ): array {
        return DB::transaction(function () use ($transaction, $data): array {
            $lockedTransaction = $this->lockTransaction($transaction);
            $amountInCents = $this->decimalToCents($data['amount']);
            $referenceNumber = trim($data['reference_number']);
            $existing = $this->findIdempotentPayment(
                $lockedTransaction,
                $data['idempotency_key']
            );

            if ($existing) {
                if (
                    $existing->payment_method !== 'gcash' ||
                    $this->decimalToCents($existing->amount) !== $amountInCents ||
                    $existing->reference_number !== $referenceNumber
                ) {
                    throw new ConflictHttpException(
                        'The idempotency key was already used with different payment data.'
                    );
                }

                $receipt = $lockedTransaction->status === 'paid'
                    ? $this->receiptService->generateForTransaction($lockedTransaction)
                    : null;

                return $this->paymentResult(
                    $existing,
                    $lockedTransaction,
                    false,
                    $receipt
                );
            }

            $this->ensurePayable($lockedTransaction);
            $settlement = $this->settlement($lockedTransaction);
            $remainingInCents = $this->decimalToCents($settlement['remaining_balance']);

            if ($amountInCents > $remainingInCents) {
                throw ValidationException::withMessages([
                    'amount' => ['GCash amount cannot exceed the remaining balance.'],
                ]);
            }

            if (
                Payment::query()
                    ->where('dining_transaction_id', $lockedTransaction->id)
                    ->where('payment_method', 'gcash')
                    ->where('reference_number', $referenceNumber)
                    ->exists()
            ) {
                throw new ConflictHttpException(
                    'This GCash reference was already recorded for the transaction.'
                );
            }

            $payment = Payment::create([
                'dining_transaction_id' => $lockedTransaction->id,
                'payment_number' => $this->generatePaymentNumber(),
                'payment_method' => 'gcash',
                'amount' => $this->formatCents($amountInCents),
                'amount_received' => null,
                'change_amount' => null,
                'reference_number' => $referenceNumber,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'completed',
                'paid_at' => now(),
            ]);

            $this->updateTransactionSettlement($lockedTransaction);
            $lockedTransaction = $lockedTransaction->fresh();
            $receipt = $lockedTransaction->status === 'paid'
                ? $this->receiptService->generateForTransaction($lockedTransaction)
                : null;

            return $this->paymentResult(
                $payment,
                $lockedTransaction,
                true,
                $receipt
            );
        });
    }

    /**
     * Return payment history with derived settlement values.
     *
     * @return array<string, mixed>
     */
    public function paymentHistory(DiningTransaction $transaction): array
    {
        $settlement = $this->settlement($transaction);

        return [
            'transaction_number' => $transaction->transaction_number,
            'status' => $transaction->status,
            'total_amount' => $settlement['total_amount'],
            'paid_amount' => $settlement['paid_amount'],
            'remaining_balance' => $settlement['remaining_balance'],
            'payments' => $transaction->payments()
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get()
                ->map(fn (Payment $payment) => $this->paymentData($payment))
                ->values(),
        ];
    }

    private function lockTransaction(
        DiningTransaction $transaction
    ): DiningTransaction {
        return DiningTransaction::query()
            ->whereKey($transaction->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function findIdempotentPayment(
        DiningTransaction $transaction,
        string $idempotencyKey
    ): ?Payment {
        return $transaction->payments()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function ensurePayable(DiningTransaction $transaction): void
    {
        if ($transaction->status === 'paid') {
            throw new ConflictHttpException(
                'This dining transaction is already fully paid.'
            );
        }

        if (! in_array($transaction->status, ['open', 'partially_paid'], true)) {
            throw new ConflictHttpException(
                'This dining transaction cannot accept payment.'
            );
        }

        if ($this->decimalToCents($this->settlement($transaction)['remaining_balance']) <= 0) {
            throw new ConflictHttpException(
                'This dining transaction has no remaining balance.'
            );
        }
    }

    /** @return array<string, string> */
    private function settlement(DiningTransaction $transaction): array
    {
        $bill = $this->billingService->calculateBill($transaction);
        $totalInCents = $this->decimalToCents($bill['total_amount']);
        $paidInCents = 0;

        foreach (
            $transaction->payments()
                ->where('status', 'completed')
                ->get(['amount']) as $payment
        ) {
            $paidInCents += $this->decimalToCents($payment->amount);
        }

        return [
            'total_amount' => $this->formatCents($totalInCents),
            'paid_amount' => $this->formatCents($paidInCents),
            'remaining_balance' => $this->formatCents(
                max($totalInCents - $paidInCents, 0)
            ),
        ];
    }

    private function updateTransactionSettlement(
        DiningTransaction $transaction
    ): void {
        $settlement = $this->settlement($transaction);
        $isPaid = $this->decimalToCents($settlement['remaining_balance']) === 0;

        $transaction->update([
            'status' => $isPaid ? 'paid' : 'partially_paid',
            'paid_at' => $isPaid ? now() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function paymentResult(
        Payment $payment,
        DiningTransaction $transaction,
        bool $created,
        $receipt = null
    ): array {
        $settlement = $this->settlement($transaction);

        return [
            'payment' => $payment,
            'transaction' => $transaction,
            'total_amount' => $settlement['total_amount'],
            'paid_amount' => $settlement['paid_amount'],
            'remaining_balance' => $settlement['remaining_balance'],
            'created' => $created,
            'receipt' => $receipt,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentData(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'payment_method' => $payment->payment_method,
            'amount' => $payment->amount,
            'amount_received' => $payment->amount_received,
            'change_amount' => $payment->change_amount,
            'reference_number' => $payment->reference_number,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }

    private function decimalToCents(mixed $amount): int
    {
        $amount = (string) $amount;
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function formatCents(int $amount): string
    {
        $negative = $amount < 0;
        $amount = abs($amount);
        $formatted = intdiv($amount, 100).'.'.str_pad(
            (string) ($amount % 100),
            2,
            '0',
            STR_PAD_LEFT
        );

        return $negative ? '-'.$formatted : $formatted;
    }

    private function generatePaymentNumber(): string
    {
        do {
            $paymentNumber =
                'PAY-'.
                now()->format('Ymd').
                '-'.
                Str::upper(Str::random(6));
        } while (
            Payment::query()
                ->where('payment_number', $paymentNumber)
                ->exists()
        );

        return $paymentNumber;
    }
}
