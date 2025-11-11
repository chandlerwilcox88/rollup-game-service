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
        Schema::create('game_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('config');  // Game-specific configuration (dice, scoring, etc.)
            $table->timestamps();
        });

        // Insert Roll Up as the first game type
        DB::table('game_types')->insert([
            'slug' => 'roll-up',
            'name' => 'Roll Up',
            'description' => 'Classic 2-dice game with bonus scoring for doubles, sevens, snake eyes, and boxcars',
            'is_active' => true,
            'config' => json_encode([
                'dice' => [
                    'count' => 2,
                    'min' => 1,
                    'max' => 6,
                ],
                'rounds' => [
                    'default' => 10,
                    'min' => 1,
                    'max' => 20,
                ],
                'scoring' => [
                    'base' => 'sum',
                    'bonuses' => [
                        [
                            'name' => 'snake_eyes',
                            'description' => 'Both dice show 1',
                            'points' => 10,
                            'priority' => 1,
                        ],
                        [
                            'name' => 'boxcars',
                            'description' => 'Both dice show 6',
                            'points' => 15,
                            'priority' => 2,
                        ],
                        [
                            'name' => 'doubles',
                            'description' => 'Both dice show the same number',
                            'points' => 5,
                            'priority' => 3,
                        ],
                        [
                            'name' => 'seven',
                            'description' => 'Dice total equals 7',
                            'points' => 3,
                            'priority' => 4,
                        ],
                    ],
                ],
                'actions' => ['roll'],
                'win_condition' => 'highest_score',
                'min_players' => 2,
                'max_players' => 6,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_types');
    }
};
