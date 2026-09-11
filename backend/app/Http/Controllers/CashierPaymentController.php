<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordCashPaymentRequest;
use App\Http\Requests\RecordGcashPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\DiningTransaction;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class CashierPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {
    }

    public function storeCash(
        RecordCashPaymentRequest $request,
        DiningTransaction $diningTransaction
    ): JsonResponse {
        $result = $this->paymentService->recordCashPayment(
            $diningTransaction,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'data' => new PaymentResource($result),
            'success' => true,
        ], $result['created'] ? 201 : 200);
    }

    public function storeGcash(
        RecordGcashPaymentRequest $request,
        DiningTransaction $diningTransaction
    ): JsonResponse {
        $result = $this->paymentService->recordGcashPayment(
            $diningTransaction,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'data' => new PaymentResource($result),
            'success' => true,
        ], $result['created'] ? 201 : 200);
    }

    public function index(
        DiningTransaction $diningTransaction
    ): JsonResponse {
        return response()->json([
            'data' => $this->paymentService->paymentHistory($diningTransaction),
            'success' => true,
        ]);
    }
}
