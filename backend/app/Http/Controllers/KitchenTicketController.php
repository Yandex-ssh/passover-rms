<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KitchenTicketController extends Controller
{
    /**
     * Display a listing of kitchen tickets.
     */
    public function index(): JsonResponse
    {
        $orders = Order::with(['tableSession.restaurantTable', 'items.menuItem'])
            ->whereIn('status', ['confirmed', 'preparing'])
            ->orderBy('confirmed_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json(['data' => $orders]);
    }

    /**
     * Display the specified ticket.
     */
    public function show(Order $order): JsonResponse
    {
        if (!in_array($order->status, ['confirmed', 'preparing', 'completed'])) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        $order->load(['tableSession.restaurantTable', 'items.menuItem']);

        return response()->json(['data' => $order]);
    }

    /**
     * Mark a confirmed order as preparing.
     */
    public function prepare(Order $order): JsonResponse
    {
        if ($order->status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed orders can be marked as preparing.'], 409);
        }

        $order->update([
            'status' => 'preparing'
        ]);

        $order->load(['tableSession.restaurantTable', 'items.menuItem']);

        return response()->json(['data' => $order]);
    }

    /**
     * Mark a preparing order as completed.
     */
    public function complete(Order $order): JsonResponse
    {
        if ($order->status !== 'preparing') {
            return response()->json(['message' => 'Only preparing orders can be marked as completed.'], 409);
        }

        $order->update([
            'status' => 'completed'
        ]);

        $order->load(['tableSession.restaurantTable', 'items.menuItem']);

        return response()->json(['data' => $order]);
    }
}
