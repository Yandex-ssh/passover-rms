<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class RestaurantTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'table_number',
        'capacity',
        'status',
        'qr_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (RestaurantTable $restaurantTable) {
            if (empty($restaurantTable->qr_token)) {
                $restaurantTable->qr_token = (string) Str::uuid();
            }
        });
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class)
            ->where('status', 'active')
            ->latestOfMany('opened_at');
    }
}
