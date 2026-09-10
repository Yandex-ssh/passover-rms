<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CashierOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashierOrderController extends Controller
{
    public function __construct(
        private readonly CashierOrderService $cashierOrderService
    ) {
    }

    /**
     * List orders waiting for cashier processing.
     */
    public function index(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('status', 'pending')
            ->with([
                'tableSession.restaurantTable',
                'items.menuItem',
            ])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * Confirm a pending order and deduct inventory.
     */
    public function confirm(Order $order): JsonResponse
    {
        $order = $this->cashierOrderService->confirmOrder($order);

        return response()->json([
            'data' => new OrderResource($order),
            'success' => true,
            'message' => 'Order confirmed successfully.',
        ]);
    }

    /**
     * Reject a pending order.
     */
    public function reject(Order $order): JsonResponse
    {
        $order = $this->cashierOrderService->rejectOrder($order);

        return response()->json([
            'data' => new OrderResource($order),
            'success' => true,
            'message' => 'Order rejected successfully.',
        ]);
    }

    /**
     * Display one order with its cashier-relevant details.
     */
    public function show(Order $order): JsonResponse
    {
        $order->load([
            'tableSession.restaurantTable',
            'items.menuItem',
        ]);

        return response()->json([
            'data' => new OrderResource($order),
            'success' => true,
        ]);
    }
}
