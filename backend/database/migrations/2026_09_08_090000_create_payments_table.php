<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_transaction_id')
                ->constrained('dining_transactions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('payment_number', 30)->unique();
            $table->string('payment_method', 20);
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_received', 10, 2)->nullable();
            $table->decimal('change_amount', 10, 2)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('idempotency_key', 100);
            $table->string('status', 20)->default('completed');
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique([
                'dining_transaction_id',
                'idempotency_key',
            ]);
            $table->unique([
                'dining_transaction_id',
                'reference_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
