<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Services\KitchenTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashierOrderService
{
    public function __construct(
        private readonly KitchenTicketService $kitchenTicketService,
        private readonly BillingService $billingService
    ) {
    }

    /**
     * Confirm a pending order and deduct its inventory atomically.
     */
    public function confirmOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== 'pending') {
                throw new ConflictHttpException(
                    'Only pending orders can be confirmed.'
                );
            }

            $orderItems = $lockedOrder->items()
                ->with('menuItem')
                ->orderBy('id')
                ->get();

            $inventoryByMenuItem = Inventory::query()
                ->whereIn('menu_item_id', $orderItems->pluck('menu_item_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('menu_item_id');

            foreach ($orderItems as $orderItem) {
                $inventory = $inventoryByMenuItem->get($orderItem->menu_item_id);

                if (! $inventory) {
                    throw ValidationException::withMessages([
                        'order' => [
                            "{$orderItem->menuItem->name} does not have inventory information.",
                        ],
                    ]);
                }

                if ($inventory->quantity < $orderItem->quantity) {
                    throw ValidationException::withMessages([
                        'order' => [
                            "Insufficient stock for {$orderItem->menuItem->name}.",
                        ],
                    ]);
                }
            }

            foreach ($orderItems as $orderItem) {
                $inventory = $inventoryByMenuItem->get($orderItem->menu_item_id);
                $quantityBefore = $inventory->quantity;
                $quantityAfter = $quantityBefore - $orderItem->quantity;

                $inventory->update([
                    'quantity' => $quantityAfter,
                ]);

                InventoryMovement::create([
                    'inventory_id' => $inventory->id,
                    'type' => 'order',
                    'quantity_change' => -$orderItem->quantity,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'reason' => "Order {$lockedOrder->order_number} confirmed",
                ]);

                $inventory->menuItem()->update([
                    'is_available' => $quantityAfter > 0,
                ]);
            }

            $lockedOrder->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $this->billingService->attachConfirmedOrder($lockedOrder);
            $this->kitchenTicketService->generateForOrder($lockedOrder);

            return $lockedOrder->fresh()->load([
                'tableSession.restaurantTable',
                'diningTransaction',
                'items.menuItem',
            ]);
        });
    }

    /**
     * Reject a pending order without changing inventory.
     */
    public function rejectOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== 'pending') {
                throw new ConflictHttpException(
                    'Only pending orders can be rejected.'
                );
            }

            $lockedOrder->update([
                'status' => 'rejected',
            ]);

            return $lockedOrder->fresh()->load([
                'tableSession.restaurantTable',
                'items.menuItem',
            ]);
        });
    }
}
