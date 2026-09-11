<?php

namespace App\Http\Controllers;

use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TableSessionController extends Controller
{
    public function close(TableSession $tableSession, BillingService $billing): JsonResponse
    {
        return DB::transaction(function () use ($tableSession, $billing): JsonResponse {
            // Order submission also locks the table, so a new order cannot slip past closure.
            $table = RestaurantTable::query()->lockForUpdate()->findOrFail($tableSession->restaurant_table_id);
            $session = TableSession::query()->lockForUpdate()->findOrFail($tableSession->id);

            abort_if($session->status !== 'active', 409, 'This dining session is already closed.');

            if ($session->orders()->whereNotIn('status', ['completed', 'rejected', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['session' => ['Complete or reject all unfinished orders before closing the session.']]);
            }

            if ($session->orders()->where('status', 'completed')->whereNull('dining_transaction_id')->exists()) {
                throw ValidationException::withMessages(['session' => ['A completed order has no bill. Resolve it before closing the session.']]);
            }

            foreach ($session->diningTransactions()->get() as $transaction) {
                if ($transaction->status !== 'paid' || ! $transaction->paid_at || $billing->calculateBill($transaction)['remaining_balance'] !== '0.00') {
                    throw ValidationException::withMessages(['session' => ['Settle all bills before closing the dining session.']]);
                }
            }

            $session->update(['status' => 'closed', 'closed_at' => now()]);
            // Do not release a table if legacy data contains another active session.
            $table->update(['status' => $table->activeSession()->exists() ? 'occupied' : 'available']);

            return response()->json(['success' => true, 'data' => [
                'id' => $session->id,
                'status' => $session->status,
                'closed_at' => $session->closed_at->toIso8601String(),
                'table_status' => $table->status,
            ]]);
        }, 3);
    }
}
