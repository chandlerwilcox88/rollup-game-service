<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GameType;

class UpdateRollUpGameTypeSeeder extends Seeder
{
    /**
     * Update the Roll Up game type with new mechanics
     *
     * @return void
     */
    public function run(): void
    {
        // Delete any Farkle game type that was created
        GameType::where('slug', 'farkle')->delete();

        // Update Roll Up game type
        GameType::updateOrCreate(
            ['slug' => 'roll-up'],
            [
                'name' => 'Roll Up',
                'description' => 'Roll Up dice game with 6 regular dice + 1 special +/- die. Score combinations like straights, full house, and N-of-a-kind. Set aside scoring dice and re-roll remaining dice to build your score, or bust and lose everything!',
                'is_active' => true,
                'config' => [
                    // Dice configuration
                    'dice' => [
                        'count' => 7, // 6 regular + 1 special
                        'regular_count' => 6,
                        'special_count' => 1,
                        'min' => 1,
                        'max' => 6,
                        'special_die' => [
                            'positive_sides' => 4, // Sides 1-4 are positive
                            'negative_sides' => 2, // Sides 5-6 are negative
                        ],
                    ],

                    // Player limits
                    'min_players' => 2,
                    'max_players' => 6,

                    // Round configuration
                    'rounds' => [
                        'default' => 10,
                        'min' => 1,
                        'max' => 20,
                    ],

                    // Allowed actions
                    'actions' => ['roll', 'hold', 'bank'],

                    // Scoring rules
                    'scoring' => [
                        'combinations' => [
                            'six_of_a_kind' => [
                                'name' => 'Six of a Kind',
                                'points' => 'value * 1000',
                                'priority' => 1,
                            ],
                            'five_of_a_kind' => [
                                'name' => 'Five of a Kind',
                                'points' => 'value * 500',
                                'priority' => 2,
                            ],
                            'four_of_a_kind' => [
                                'name' => 'Four of a Kind',
                                'points' => 'value * 200',
                                'priority' => 3,
                            ],
                            'three_of_a_kind' => [
                                'name' => 'Three of a Kind',
                                'points' => 'value * 100 (1s = 1000)',
                                'priority' => 4,
                            ],
                            'large_straight' => [
                                'name' => 'Large Straight (1-2-3-4-5-6)',
                                'points' => 1500,
                                'priority' => 1,
                            ],
                            'small_straight' => [
                                'name' => 'Small Straight (1-2-3-4-5)',
                                'points' => 500,
                                'priority' => 2,
                            ],
                            'full_house' => [
                                'name' => 'Full House (3 of one + 2 of another)',
                                'points' => 300,
                                'priority' => 3,
                            ],
                            'sum' => [
                                'name' => 'Sum (no special combo)',
                                'points' => 'sum of all dice',
                                'priority' => 999,
                            ],
                        ],
                    ],

                    // Game rules
                    'rules' => [
                        'turn_based' => true,
                        'multi_roll_turns' => true,
                        'bust_on_no_combo' => true,
                        'hot_dice' => true, // Roll all 6 again if all score
                        'negative_scoring' => true,
                        'provably_fair' => true,
                    ],
                ],
            ]
        );

        $this->command->info('Roll Up game type updated successfully!');
    }
}
