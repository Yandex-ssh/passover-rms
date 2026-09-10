<?php

namespace App\Http\Controllers;

use App\Http\Resources\MenuItemResource;
use App\Models\Inventory;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierMenuController extends Controller
{
    /**
     * Return the cashier-only menu view, including current stock quantities.
     */
    public function index(): AnonymousResourceCollection
    {
        return MenuItemResource::collection(
            MenuItem::query()->with(['category', 'inventory'])->latest()->get()
        );
    }

    /**
     * Let a cashier temporarily stop or resume selling an in-stock menu item.
     */
    public function updateAvailability(Request $request, MenuItem $menuItem): JsonResponse
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $menuItem = DB::transaction(function () use ($menuItem, $validated): MenuItem {
            $lockedItem = MenuItem::query()->whereKey($menuItem->id)->lockForUpdate()->firstOrFail();
            $inventory = Inventory::query()->where('menu_item_id', $lockedItem->id)->lockForUpdate()->first();

            if ($validated['is_available'] && (! $inventory || $inventory->quantity <= 0)) {
                throw ValidationException::withMessages([
                    'is_available' => ['An out-of-stock item cannot be marked available. Restock it first.'],
                ]);
            }

            $lockedItem->update(['is_available' => $validated['is_available']]);

            return $lockedItem->fresh()->load(['category', 'inventory']);
        });

        return response()->json([
            'data' => new MenuItemResource($menuItem),
            'success' => true,
            'message' => $menuItem->is_available ? 'Menu item marked available.' : 'Menu item marked unavailable.',
        ]);
    }
}
