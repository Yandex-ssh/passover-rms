<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerOrderStatusResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class CustomerOrderStatusController extends Controller
{
    public function show(string $trackingToken): JsonResponse
    {
        $order = Order::query()
            ->where('tracking_token', $trackingToken)
            ->firstOrFail();

        return response()->json([
            'data' => new CustomerOrderStatusResource($order),
            'success' => true,
        ]);
    }
}
