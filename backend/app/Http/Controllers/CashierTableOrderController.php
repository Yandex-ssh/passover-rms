<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitCustomerOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\RestaurantTable;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class CashierTableOrderController extends Controller
{
    public function store(SubmitCustomerOrderRequest $request, RestaurantTable $restaurantTable, OrderService $orders): JsonResponse
    {
        $order = $orders->submitTableOrder($restaurantTable, $request->validated());

        return (new OrderResource($order))->additional(['success' => true])->response()->setStatusCode(201);
    }
}
