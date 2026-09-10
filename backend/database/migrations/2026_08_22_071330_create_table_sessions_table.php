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
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_table_id')
                ->constrained('restaurant_tables')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->uuid('session_token')
                ->unique();

            $table->string('status', 20)
                ->default('active');

            $table->timestamp('opened_at');

            $table->timestamp('closed_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'restaurant_table_id',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
