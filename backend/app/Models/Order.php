<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'table_session_id',
        'dining_transaction_id',
        'order_number',
        'status',
        'customer_note',
        'subtotal',
        'submitted_at',
        'confirmed_at',
        'confirmed_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->tracking_token)) {
                $order->tracking_token = (string) Str::uuid();
            }
        });
    }

    protected $casts = [
        'subtotal' => 'decimal:2',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function tableSession()
    {
        return $this->belongsTo(TableSession::class);
    }

    public function diningTransaction(): BelongsTo
    {
        return $this->belongsTo(DiningTransaction::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function kitchenTicket(): HasOne
    {
        return $this->hasOne(KitchenTicket::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
