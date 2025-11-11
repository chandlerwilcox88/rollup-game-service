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
        // Add turn state to games table
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('current_player_id')->nullable()->after('current_round');
            $table->jsonb('turn_state')->nullable()->after('current_player_id');
            // turn_state structure:
            // {
            //   "held_dice": [1, 5, 1],        // Dice values that have been set aside
            //   "available_dice": [4, 2, 3],   // Dice values still in play
            //   "pending_score": 300,          // Points accumulated this turn
            //   "can_hold": true,              // Whether player can hold dice
            //   "can_bank": true,              // Whether player can bank points
            //   "can_roll": true,              // Whether player can roll
            //   "turn_roll_count": 2           // Number of rolls in this turn
            // }

            $table->foreign('current_player_id')->references('id')->on('game_players')->onDelete('set null');
        });

        // Add Farkle-specific fields to player_rolls table
        Schema::table('player_rolls', function (Blueprint $table) {
            // Drop the unique constraint on round_number since players can roll multiple times per round
            $table->dropUnique(['game_id', 'game_player_id', 'round_number']);

            // Add new fields for Farkle
            $table->integer('turn_number')->default(1)->after('round_number');
            $table->integer('roll_sequence')->default(1)->after('turn_number');
            $table->jsonb('held_dice')->nullable()->after('total_points');
            $table->jsonb('available_dice')->nullable()->after('held_dice');
            $table->integer('pending_score')->default(0)->after('available_dice');
            $table->boolean('is_bust')->default(false)->after('pending_score');
            $table->string('action_type', 20)->default('roll')->after('is_bust'); // roll, bank, bust
            $table->integer('target_player_id')->nullable()->after('action_type'); // For negative point assignment

            // Add new composite unique constraint
            $table->unique(['game_id', 'game_player_id', 'round_number', 'roll_sequence'], 'player_roll_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_rolls', function (Blueprint $table) {
            // Remove Farkle fields
            $table->dropUnique('player_roll_unique');
            $table->dropColumn([
                'turn_number',
                'roll_sequence',
                'held_dice',
                'available_dice',
                'pending_score',
                'is_bust',
                'action_type',
                'target_player_id',
            ]);

            // Restore original unique constraint
            $table->unique(['game_id', 'game_player_id', 'round_number']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['current_player_id']);
            $table->dropColumn(['current_player_id', 'turn_state']);
        });
    }
};
