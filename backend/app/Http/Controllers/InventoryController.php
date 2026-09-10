<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryMovement;

class InventoryController extends Controller
{
    /**
     * Display all inventory records.
     */
    public function index(): AnonymousResourceCollection
    {
        $inventories = Inventory::query()
            ->with('menuItem')
            ->orderBy('quantity')
            ->get();

        return InventoryResource::collection($inventories);
    }

    /**
     * Display one inventory record.
     */
    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load('menuItem');

        return response()->json([
            'data' => new InventoryResource($inventory),
            'success' => true,
        ]);
        
    }

        /**
     * Set the verified inventory quantity and record the adjustment.
     */
    public function adjust(
        Request $request,
        Inventory $inventory
    ): JsonResponse {
        $validated = $request->validate([
            'quantity' => [
                'required',
                'integer',
                'min:0',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $adjustedInventory = DB::transaction(function () use ($inventory, $validated) {
            $lockedInventory = Inventory::query()
                ->whereKey($inventory->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = $lockedInventory->quantity;
            $quantityAfter = $validated['quantity'];
            $quantityChange = $quantityAfter - $quantityBefore;

            $lockedInventory->update([
                'quantity' => $quantityAfter,
            ]);

            $lockedInventory->menuItem()->update([
                'is_available' => $quantityAfter > 0,
            ]);

            InventoryMovement::create([
                'inventory_id' => $lockedInventory->id,
                'type' => 'adjustment',
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $validated['reason'] ?? 'Inventory adjustment',
            ]);

            return $lockedInventory
                ->fresh()
                ->load('menuItem');
        });

        return response()->json([
            'data' => new InventoryResource($adjustedInventory),
            'success' => true,
            'message' => 'Inventory adjusted successfully.',
        ]);
    }

    /**
     * Add stock to an inventory record.
     */
    public function restock(
        Request $request,
        Inventory $inventory
    ): JsonResponse {
        $validated = $request->validate([
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $inventory = DB::transaction(function () use ($inventory, $validated) {

            $lockedInventory = Inventory::query()
                ->whereKey($inventory->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = $lockedInventory->quantity;

            $quantityAfter =
                $quantityBefore + $validated['quantity'];

            $lockedInventory->update([
                'quantity' => $quantityAfter,
                'last_restocked_at' => now(),
            ]);

            $lockedInventory->menuItem()->update([
                'is_available' => true,
            ]);

            InventoryMovement::create([
                'inventory_id' => $lockedInventory->id,
                'type' => 'restock',
                'quantity_change' => $validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $validated['reason'] ?? 'Inventory restock',
            ]);

            return $lockedInventory
                ->fresh()
                ->load('menuItem');
        });

        return response()->json([
            'data' => new InventoryResource($inventory),
            'success' => true,
            'message' => 'Inventory restocked successfully.',
        ]);
    }
}