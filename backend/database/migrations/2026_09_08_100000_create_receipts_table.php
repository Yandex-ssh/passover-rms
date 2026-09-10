<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_transaction_id')
                ->unique()
                ->constrained('dining_transactions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('receipt_number', 30)->unique();
            $table->string('status', 20)->default('generated');
            $table->json('snapshot');
            $table->timestamp('generated_at');
            $table->timestamp('printed_at')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
