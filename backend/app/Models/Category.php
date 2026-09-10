<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

        /**
     * A Category has many Menu Items.
     */
    public function menuItems()
    {
        return $this->hasMany(MenuItem::class);
    }
}
