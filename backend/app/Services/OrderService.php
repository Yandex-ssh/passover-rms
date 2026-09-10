<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Submit a new customer order from a table QR code.
     */
    public function submitCustomerOrder(
        string $qrToken,
        array $data
    ): Order {
        $restaurantTable = RestaurantTable::query()
            ->where('qr_token', $qrToken)
            ->firstOrFail();

        return DB::transaction(function () use ($restaurantTable, $data) {
            $restaurantTable = RestaurantTable::query()
                ->lockForUpdate()
                ->findOrFail($restaurantTable->id);

            $tableSession = $restaurantTable->activeSession()->first();

            if (! $tableSession && $restaurantTable->status === 'available') {
                $tableSession = $restaurantTable->sessions()->create([
                    'status' => 'active',
                ]);
                $restaurantTable->update(['status' => 'occupied']);
            }

            if (! $tableSession) {
                throw ValidationException::withMessages([
                    'table' => [
                        'This table is not currently accepting orders.',
                    ],
                ]);
            }

            $order = Order::create([
                'table_session_id' => $tableSession->id,
                'order_number' => $this->generateOrderNumber(),
                'status' => 'pending',
                'customer_note' => $data['customer_note'] ?? null,
                'subtotal' => 0,
                'submitted_at' => now(),
            ]);

            $orderSubtotal = 0;

            foreach ($data['items'] as $itemData) {

                $menuItem = MenuItem::query()
                    ->with([
                        'category',
                        'inventory',
                    ])
                    ->findOrFail($itemData['menu_item_id']);

                if (! $menuItem->category->is_active) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "{$menuItem->name} is currently unavailable.",
                        ],
                    ]);
                }

                if (! $menuItem->is_available) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "{$menuItem->name} is currently unavailable.",
                        ],
                    ]);
                }

                if (! $menuItem->inventory) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "{$menuItem->name} does not have inventory information.",
                        ],
                    ]);
                }

                if (
                    $menuItem->inventory->quantity <
                    $itemData['quantity']
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Only {$menuItem->inventory->quantity} unit(s) of {$menuItem->name} are currently available.",
                        ],
                    ]);
                }

                $unitPrice = (float) $menuItem->price;

                $itemSubtotal = round(
                    $unitPrice * $itemData['quantity'],
                    2
                );

                $order->items()->create([
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'special_instruction' =>
                        $itemData['special_instruction'] ?? null,
                ]);

                $orderSubtotal += $itemSubtotal;
            }

            $order->update([
                'subtotal' => round($orderSubtotal, 2),
            ]);

            return $order
                ->fresh()
                ->load([
                    'tableSession.restaurantTable',
                    'items.menuItem',
                ]);
        });
    }

    /**
     * Generate a human-readable unique order number.
     */
    private function generateOrderNumber(): string
    {
        do {
            $orderNumber =
                'ORD-' .
                now()->format('Ymd') .
                '-' .
                Str::upper(Str::random(6));
        } while (
            Order::query()
                ->where('order_number', $orderNumber)
                ->exists()
        );

        return $orderNumber;
    }
}
