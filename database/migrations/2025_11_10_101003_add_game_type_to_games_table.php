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
        Schema::table('games', function (Blueprint $table) {
            // Add game_type_id column (foreign key to game_types)
            $table->unsignedBigInteger('game_type_id')->nullable()->after('id');

            // Add game_config for game-specific overrides
            $table->jsonb('game_config')->nullable()->after('settings');

            // Add foreign key constraint
            $table->foreign('game_type_id')
                  ->references('id')
                  ->on('game_types')
                  ->onDelete('restrict');
        });

        // Set default game_type_id to Roll Up (id = 1) for all existing games
        DB::table('games')->whereNull('game_type_id')->update(['game_type_id' => 1]);

        // Make game_type_id non-nullable after setting defaults
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('game_type_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['game_type_id']);

            // Drop columns
            $table->dropColumn(['game_type_id', 'game_config']);
        });
    }
};
