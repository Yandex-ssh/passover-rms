<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TableSession extends Model
{
    protected $fillable = [
        'restaurant_table_id',
        'session_token',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TableSession $session): void {
            if (empty($session->session_token)) {
                $session->session_token = (string) Str::uuid();
            }

            if (empty($session->opened_at)) {
                $session->opened_at = now();
            }
        });
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function diningTransactions(): HasMany
    {
        return $this->hasMany(DiningTransaction::class);
    }
}
