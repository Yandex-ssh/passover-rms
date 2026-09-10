<?php

namespace App\Http\Controllers;

use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;

class CustomerTableController extends Controller
{
    public function show(string $qrToken): JsonResponse
    {
        $table = RestaurantTable::query()
            ->where('qr_token', $qrToken)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'table_number' => $table->table_number,
                'capacity' => $table->capacity,
                // An available table starts its dining session when the first
                // customer order is placed. A table with an existing session
                // can also continue receiving orders.
                'accepts_orders' => $table->status === 'available'
                    || $table->activeSession()->exists(),
            ],
            'success' => true,
        ]);
    }
}
