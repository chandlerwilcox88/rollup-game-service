<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('player_rolls', function (Blueprint $table) {
            // Add dice_values JSONB column for flexible dice storage
            // Format: [5, 3] for 2-dice games, [1, 2, 3, 4, 5] for 5-dice games, etc.
            $table->jsonb('dice_values')->nullable()->after('dice2_value');
        });

        // Migrate existing data: convert dice1_value and dice2_value into dice_values array
        // This ensures backward compatibility for existing Roll Up games
        DB::statement("
            UPDATE player_rolls
            SET dice_values = json_build_array(dice1_value, dice2_value)::jsonb
            WHERE dice_values IS NULL
            AND dice1_value IS NOT NULL
            AND dice2_value IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_rolls', function (Blueprint $table) {
            $table->dropColumn('dice_values');
        });
    }
};
