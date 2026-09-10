<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'dining_transaction_id',
        'receipt_number',
        'status',
        'snapshot',
        'generated_at',
        'printed_at',
        'print_count',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'printed_at' => 'datetime',
        'print_count' => 'integer',
    ];

    public function diningTransaction(): BelongsTo
    {
        return $this->belongsTo(DiningTransaction::class);
    }
}
