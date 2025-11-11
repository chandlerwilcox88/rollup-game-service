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
        Schema::create('room_game_history', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 8)->index();
            $table->uuid('game_id')->index();
            $table->integer('game_number'); // Sequential game # in this room
            $table->bigInteger('winner_user_id');
            $table->string('winner_username');
            $table->integer('total_rounds')->default(10);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->onDelete('cascade');
            $table->index(['room_code', 'game_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_game_history');
    }
};
