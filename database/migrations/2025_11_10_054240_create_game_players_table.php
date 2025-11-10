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
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->uuid('game_id');
            $table->bigInteger('user_id');
            $table->string('username', 255);
            $table->integer('position'); // 1-6
            $table->string('client_seed', 64);
            $table->integer('total_score')->default(0);
            $table->integer('placement')->nullable(); // 1st, 2nd, 3rd, etc.
            $table->string('status', 20)->default('active'); // active, finished, disconnected
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->onDelete('cascade');
            $table->unique(['game_id', 'user_id']);
            $table->index('game_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
