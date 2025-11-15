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
        Schema::table('player_rolls', function (Blueprint $table) {
            $table->integer('dice1_value')->nullable()->change();
            $table->integer('dice2_value')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_rolls', function (Blueprint $table) {
            $table->integer('dice1_value')->nullable(false)->change();
            $table->integer('dice2_value')->nullable(false)->change();
        });
    }
};
