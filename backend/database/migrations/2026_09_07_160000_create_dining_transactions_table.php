<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_session_id')
                ->constrained('table_sessions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('transaction_number', 30)->unique();
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->timestamps();

            $table->index(['table_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_transactions');
    }
};
