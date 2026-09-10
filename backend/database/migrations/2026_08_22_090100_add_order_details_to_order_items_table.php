<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('menu_item_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->string('special_instruction', 255)->nullable();

            $table->unique(['order_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'menu_item_id']);
            $table->dropConstrainedForeignId('menu_item_id');
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn([
                'quantity',
                'unit_price',
                'subtotal',
                'special_instruction',
            ]);
        });
    }
};
