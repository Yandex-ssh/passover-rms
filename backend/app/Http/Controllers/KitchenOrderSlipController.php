<?php

namespace App\Http\Controllers;

use App\Http\Resources\KitchenOrderSlipResource;
use App\Models\KitchenTicket;
use App\Services\KitchenTicketService;
use Illuminate\Http\JsonResponse;

class KitchenOrderSlipController extends Controller
{
    public function __construct(
        private readonly KitchenTicketService $kitchenTicketService
    ) {
    }

    /**
     * Display a permanent kitchen order slip.
     */
    public function show(KitchenTicket $kitchenTicket): JsonResponse
    {
        $kitchenTicket->load([
            'order.tableSession.restaurantTable',
            'order.items.menuItem',
        ]);

        return response()->json([
            'data' => new KitchenOrderSlipResource($kitchenTicket),
            'success' => true,
        ]);
    }

    /**
     * Record one backend print operation.
     */
    public function printed(KitchenTicket $kitchenTicket): JsonResponse
    {
        $kitchenTicket = $this->kitchenTicketService->markPrinted($kitchenTicket);
        $kitchenTicket->load([
            'order.tableSession.restaurantTable',
            'order.items.menuItem',
        ]);

        return response()->json([
            'data' => new KitchenOrderSlipResource($kitchenTicket),
            'success' => true,
            'message' => 'Kitchen order slip print recorded.',
        ]);
    }
}
