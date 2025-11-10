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
        Schema::create('player_rolls', function (Blueprint $table) {
            $table->id();
            $table->uuid('game_id');
            $table->unsignedBigInteger('game_player_id');
            $table->integer('round_number');
            $table->integer('nonce'); // For provably fair verification
            $table->integer('dice1_value'); // 1-6
            $table->integer('dice2_value'); // 1-6
            $table->integer('roll_total');
            $table->integer('bonus_points')->default(0);
            $table->integer('total_points'); // roll_total + bonus_points
            $table->timestamp('rolled_at')->useCurrent();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->onDelete('cascade');
            $table->foreign('game_player_id')->references('id')->on('game_players')->onDelete('cascade');
            $table->unique(['game_id', 'game_player_id', 'round_number']);
            $table->index('game_id');
            $table->index('game_player_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_rolls');
    }
};
