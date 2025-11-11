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
        Schema::create('room_player_stats', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 8)->index();
            $table->bigInteger('user_id')->index();
            $table->string('username');
            $table->integer('games_played')->default(0);
            $table->integer('games_won')->default(0);
            $table->integer('total_score')->default(0);
            $table->decimal('average_score', 10, 2)->default(0);
            $table->integer('best_score')->default(0);
            $table->integer('first_place_finishes')->default(0);
            $table->integer('second_place_finishes')->default(0);
            $table->integer('third_place_finishes')->default(0);
            $table->timestamps();

            $table->unique(['room_code', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_player_stats');
    }
};
