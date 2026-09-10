<?php

namespace App\Http\Controllers;

use App\Http\Resources\DiningTransactionResource;
use App\Models\DiningTransaction;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;

class CashierBillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService
    ) {
    }

    /**
     * Display the current calculated bill for a dining transaction.
     */
    public function bill(DiningTransaction $diningTransaction): JsonResponse
    {
        return (new DiningTransactionResource(
            $this->billingService->calculateBill($diningTransaction)
        ))->response();
    }
}
