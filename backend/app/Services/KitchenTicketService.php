<?php

namespace App\Services;

use App\Models\KitchenTicket;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KitchenTicketService
{
    /**
     * Generate or return the one permanent ticket for a confirmed order.
     */
    public function generateForOrder(Order $order): KitchenTicket
    {
        return DB::transaction(function () use ($order): KitchenTicket {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->with('kitchenTicket')
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'order' => ['Only confirmed orders can receive a kitchen ticket.'],
                ]);
            }

            if ($lockedOrder->kitchenTicket) {
                return $lockedOrder->kitchenTicket;
            }

            do {
                $ticketNumber =
                    'KOT-' .
                    now()->format('Ymd') .
                    '-' .
                    Str::upper(Str::random(6));
            } while (KitchenTicket::query()->where('ticket_number', $ticketNumber)->exists());

            return KitchenTicket::create([
                'order_id' => $lockedOrder->id,
                'ticket_number' => $ticketNumber,
                'status' => 'generated',
                'generated_at' => now(),
                'print_count' => 0,
            ]);
        });
    }

    /**
     * Record one backend print operation for a ticket.
     */
    public function markPrinted(KitchenTicket $ticket): KitchenTicket
    {
        return DB::transaction(function () use ($ticket): KitchenTicket {
            $lockedTicket = KitchenTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTicket->update([
                'status' => 'printed',
                'printed_at' => now(),
                'print_count' => $lockedTicket->print_count + 1,
            ]);

            return $lockedTicket->fresh();
        });
    }
}
