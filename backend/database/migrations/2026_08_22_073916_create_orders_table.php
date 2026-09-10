<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('table_session_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('order_number', 30)
                ->unique();

            $table->string('status', 30)
                ->default('pending');

            $table->text('customer_note')
                ->nullable();

            $table->decimal('subtotal', 10, 2)
                ->default(0);

            $table->timestamp('submitted_at')
                ->nullable();

            $table->timestamp('confirmed_at')
                ->nullable();

            $table->unsignedBigInteger('confirmed_by')
                ->nullable();

            $table->timestamps();

            $table->index([
                'table_session_id',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
