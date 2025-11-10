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
        Schema::create('games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('room_code', 8)->index();
            $table->string('status', 20)->index(); // waiting, in_progress, completed, cancelled
            $table->string('server_seed', 64);
            $table->string('server_seed_hash', 64);
            $table->integer('total_rounds')->default(10);
            $table->integer('current_round')->default(0);
            $table->integer('turn_time_limit')->default(15); // seconds
            $table->jsonb('settings')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
