<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record exactly which die positions a roll threw and the nonce each one used,
 * so every die of every roll can be re-derived from
 * HMAC-SHA256(serverSeed, "serverSeed:clientSeed:nonce") after the reveal.
 * Both columns are nullable, so this is safe to run on a live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_rolls', function (Blueprint $table) {
            $table->jsonb('rolled_indices')->nullable();
            $table->jsonb('nonces')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('player_rolls', function (Blueprint $table) {
            $table->dropColumn(['rolled_indices', 'nonces']);
        });
    }
};
