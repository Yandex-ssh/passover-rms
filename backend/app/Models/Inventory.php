<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id',
        'quantity',
        'low_stock_threshold',
        'last_restocked_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'last_restocked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (Inventory $inventory): void {
            if ($inventory->wasChanged('quantity') && $inventory->quantity <= 0) {
                $inventory->menuItem()->update(['is_available' => false]);
            }
        });
    }

    /**
     * An inventory record belongs to one menu item.
     */
    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * An inventory record has many stock movements.
     */
    public function movements()
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
