<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitCustomerOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class CustomerOrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    /**
     * Submit a customer order using a table QR token.
     */
    public function store(
        SubmitCustomerOrderRequest $request,
        string $qrToken
    ): JsonResponse {
        $order = $this->orderService->submitCustomerOrder(
            $qrToken,
            $request->validated()
        );

        return response()->json([
            'data' => new OrderResource($order),
            'success' => true,
            'message' => 'Order submitted successfully and is awaiting cashier confirmation.',
        ], 201);
    }
}