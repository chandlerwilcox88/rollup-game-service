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
        Schema::table('game_players', function (Blueprint $table) {
            // Per-player turn state for simultaneous gameplay
            // Each player tracks their own held dice, pending score, and action availability
            $table->json('turn_state')->nullable()->after('total_score');

            // Structure:
            // {
            //   "held_dice": [2, 2, 2],        // Dice values held across rolls
            //   "held_dice_indices": [0, 1, 3], // Indices of held dice from last roll
            //   "available_dice": [4, 5, 6],   // Dice values from current roll (not yet held)
            //   "pending_score": 200,          // Points accumulated this round
            //   "can_hold": true,              // Whether player can hold dice now
            //   "can_bank": false,             // Whether player can bank points
            //   "can_roll": false,             // Whether player can roll
            //   "turn_roll_count": 1,          // Number of rolls taken this round
            //   "is_bust": false,              // Whether player busted
            //   "is_banked": false,            // Whether player banked this round
            //   "special_die_value": 3         // The +/- die result for this round
            // }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn('turn_state');
        });
    }
};
