<?php

namespace App\Services;

use App\Models\OrderItem;
use Carbon\Carbon;

class BestSellingService
{
    /** Sales rankings shared by staff reports and the public assistant. */
    public function ranked(?string $from, ?string $to, bool $availableOnly = false, array $categoryIds = []): array
    {
        return OrderItem::query()
            ->select('menu_item_id')
            ->selectRaw('SUM(quantity) as quantity_sold, SUM(subtotal) as sales_amount, COUNT(DISTINCT order_id) as order_count')
            ->whereHas('order', function ($query) use ($from, $to): void {
                $query->whereIn('status', ['confirmed', 'preparing', 'completed'])
                    ->whereHas('diningTransaction', function ($transactions) use ($from, $to): void {
                        $transactions->where('status', 'paid')->whereNotNull('paid_at')
                            ->when($from, fn ($q) => $q->where('paid_at', '>=', Carbon::parse($from, 'UTC')->startOfDay()))
                            ->when($to, fn ($q) => $q->where('paid_at', '<=', Carbon::parse($to, 'UTC')->endOfDay()));
                    });
            })
            ->when($availableOnly, fn ($q) => $q->whereHas('menuItem', fn ($items) => $items
                ->where('is_available', true)->whereHas('category', fn ($categories) => $categories->where('is_active', true))
                ->whereHas('inventory', fn ($inventory) => $inventory->where('quantity', '>', 0))))
            ->when($categoryIds, fn ($q) => $q->whereHas('menuItem', fn ($items) => $items->whereIn('category_id', $categoryIds)))
            ->with('menuItem:id,name')
            ->groupBy('menu_item_id')->orderByDesc('quantity_sold')->orderByDesc('sales_amount')->orderBy('menu_item_id')
            ->get()->map(fn ($row) => [
                'menu_item_id' => (int) $row->menu_item_id,
                'name' => $row->menuItem->name,
                'quantity_sold' => (int) $row->quantity_sold,
                'sales_amount' => number_format((float) $row->sales_amount, 2, '.', ''),
                'order_count' => (int) $row->order_count,
            ])->all();
    }
}
