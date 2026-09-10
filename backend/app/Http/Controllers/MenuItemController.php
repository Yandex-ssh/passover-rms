<?php

namespace App\Http\Controllers;

use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;


class MenuItemController extends Controller
{
    /**
     * Display all menu items with their category.
     */
    public function index(): AnonymousResourceCollection
    {
        $menuItems = MenuItem::query()
            ->with('category')
            ->latest()
            ->get();

        return MenuItemResource::collection($menuItems);
    }

    /**
     * Store a newly created menu item.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'price' => [
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],
            'image' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $menuItem = DB::transaction(function () use ($validated) {
            $menuItem = MenuItem::create([
                ...$validated,
                'is_available' => false,
            ]);

            Inventory::create([
                'menu_item_id' => $menuItem->id,
                'quantity' => 0,
                'low_stock_threshold' => 5,
            ]);

            return $menuItem;
        });

        $menuItem->load('category', 'inventory');

        return response()->json([
            'data' => new MenuItemResource($menuItem),
            'success' => true,
            'message' => 'Menu item created successfully.',
        ], 201);
    }

    /**
     * Display one menu item.
     */
    public function show(MenuItem $menuItem): JsonResponse
    {
        $menuItem->load('category');

        return response()->json([
            'data' => new MenuItemResource($menuItem),
            'success' => true,
        ]);
    }

    /**
     * Update an existing menu item.
     */
    public function update(
        Request $request,
        MenuItem $menuItem
    ): JsonResponse {
        $validated = $request->validate([
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],
            'image' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'is_available' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $menuItem->update($validated);
        $menuItem->load('category');

        return response()->json([
            'data' => new MenuItemResource($menuItem->fresh()->load('category')),
            'success' => true,
            'message' => 'Menu item updated successfully.',
        ]);
    }

    /**
     * Delete a menu item.
     */
    public function destroy(MenuItem $menuItem): JsonResponse
    {
        $menuItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Menu item deleted successfully.',
        ]);
    }
}