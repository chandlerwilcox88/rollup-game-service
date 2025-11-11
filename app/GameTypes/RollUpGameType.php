<?php

namespace App\GameTypes;

/**
 * RollUpGameType
 *
 * Implementation of Roll Up dice game with 6 regular dice + 1 special +/- die.
 *
 * Game Rules:
 * - 6 regular dice + 1 special +/- die
 * - Scoring combinations:
 *   - Three of a kind: value × 100 (three 1s = 1000)
 *   - Four of a kind: value × 200
 *   - Five of a kind: value × 500
 *   - Six of a kind: value × 1000
 *   - Small straight (1-2-3-4-5): 500 points
 *   - Large straight (1-2-3-4-5-6): 1500 points
 *   - Full house (3+2): 300 points
 *   - No combo: sum of 6 dice
 * - Special die (4 positive sides, 2 negative sides):
 *   - Positive (+): score goes to current player
 *   - Negative (-): player assigns score as negative points to another player
 * - Multi-roll turns: Set aside scoring dice, re-roll remaining
 * - Bust: If no scoring combo on re-roll, lose all pending points
 */
class RollUpGameType extends AbstractGameType
{
    /**
     * {@inheritdoc}
     */
    public function calculateScore(array $diceValues, array $context = []): array
    {
        // Roll Up uses 6 regular dice + 1 special die
        if (count($diceValues) !== 7) {
            throw new \InvalidArgumentException('Roll Up requires exactly 7 dice (6 regular + 1 special)');
        }

        // Separate regular dice from special die (last one)
        $regularDice = array_slice($diceValues, 0, 6);
        $specialDie = $diceValues[6];

        // Detect all scoring combinations
        $combinations = $this->detectCombinations($regularDice);

        // Get the best (highest scoring) combination
        $bestCombo = $this->getBestCombination($combinations);

        // Calculate final score
        $rollTotal = $bestCombo['points'];
        $bonusPoints = 0;
        $combosApplied = [$bestCombo];

        // Handle special die (+/- determination)
        $isPositive = $this->isSpecialDiePositive($specialDie);

        return [
            'dice' => $diceValues,
            'regular_dice' => $regularDice,
            'special_die' => $specialDie,
            'special_die_positive' => $isPositive,
            'roll_total' => $rollTotal,
            'bonus_points' => $bonusPoints,
            'total_points' => $rollTotal + $bonusPoints,
            'combinations' => $combinations,
            'best_combination' => $bestCombo,
            'combos_applied' => $combosApplied,
        ];
    }

    /**
     * Detect all possible scoring combinations in the dice
     *
     * @param array $dice Array of 6 dice values
     * @return array Array of detected combinations with points
     */
    protected function detectCombinations(array $dice): array
    {
        $combinations = [];

        // Count frequency of each die value
        $frequency = array_count_values($dice);
        arsort($frequency); // Sort by frequency descending

        // Check for Large Straight (1-2-3-4-5-6)
        if ($this->isLargeStraight($dice)) {
            $combinations[] = [
                'name' => 'large_straight',
                'description' => 'Large Straight (1-2-3-4-5-6)',
                'points' => 1500,
                'dice_used' => $dice,
                'priority' => 1,
            ];
        }

        // Check for Small Straight (1-2-3-4-5)
        if ($this->isSmallStraight($dice)) {
            $combinations[] = [
                'name' => 'small_straight',
                'description' => 'Small Straight (1-2-3-4-5)',
                'points' => 500,
                'dice_used' => [1, 2, 3, 4, 5],
                'priority' => 2,
            ];
        }

        // Check for Six of a kind
        foreach ($frequency as $value => $count) {
            if ($count === 6) {
                $combinations[] = [
                    'name' => 'six_of_a_kind',
                    'description' => "Six {$value}s",
                    'points' => $value * 1000,
                    'dice_used' => array_fill(0, 6, $value),
                    'priority' => 1,
                ];
            }
        }

        // Check for Five of a kind
        foreach ($frequency as $value => $count) {
            if ($count === 5) {
                $combinations[] = [
                    'name' => 'five_of_a_kind',
                    'description' => "Five {$value}s",
                    'points' => $value * 500,
                    'dice_used' => array_fill(0, 5, $value),
                    'priority' => 2,
                ];
            }
        }

        // Check for Four of a kind
        foreach ($frequency as $value => $count) {
            if ($count === 4) {
                $combinations[] = [
                    'name' => 'four_of_a_kind',
                    'description' => "Four {$value}s",
                    'points' => $value * 200,
                    'dice_used' => array_fill(0, 4, $value),
                    'priority' => 3,
                ];
            }
        }

        // Check for Full House (3 of one + 2 of another)
        if ($this->isFullHouse($frequency)) {
            $combinations[] = [
                'name' => 'full_house',
                'description' => 'Full House',
                'points' => 300,
                'dice_used' => $dice,
                'priority' => 3,
            ];
        }

        // Check for Three of a kind
        foreach ($frequency as $value => $count) {
            if ($count === 3) {
                $points = $value === 1 ? 1000 : ($value * 100);
                $combinations[] = [
                    'name' => 'three_of_a_kind',
                    'description' => "Three {$value}s",
                    'points' => $points,
                    'dice_used' => array_fill(0, 3, $value),
                    'priority' => 4,
                ];
            }
        }

        // If no special combination, use sum of all dice
        if (empty($combinations)) {
            $sum = array_sum($dice);
            $combinations[] = [
                'name' => 'sum',
                'description' => 'Sum of Dice',
                'points' => $sum,
                'dice_used' => $dice,
                'priority' => 999,
            ];
        }

        return $combinations;
    }

    /**
     * Get the best (highest scoring) combination
     *
     * @param array $combinations
     * @return array
     */
    protected function getBestCombination(array $combinations): array
    {
        if (empty($combinations)) {
            return [
                'name' => 'none',
                'description' => 'No Scoring Combo',
                'points' => 0,
                'dice_used' => [],
                'priority' => 999,
            ];
        }

        // Sort by points descending, then by priority ascending
        usort($combinations, function ($a, $b) {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }
            return $a['priority'] <=> $b['priority'];
        });

        return $combinations[0];
    }

    /**
     * Check if dice form a large straight (1-2-3-4-5-6)
     *
     * @param array $dice
     * @return bool
     */
    protected function isLargeStraight(array $dice): bool
    {
        $sorted = $dice;
        sort($sorted);
        return $sorted === [1, 2, 3, 4, 5, 6];
    }

    /**
     * Check if dice contain a small straight (1-2-3-4-5)
     *
     * @param array $dice
     * @return bool
     */
    protected function isSmallStraight(array $dice): bool
    {
        $unique = array_unique($dice);
        sort($unique);

        // Check if we have 1-2-3-4-5 in the dice
        $requiredValues = [1, 2, 3, 4, 5];
        foreach ($requiredValues as $value) {
            if (!in_array($value, $unique)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if frequency map represents a full house
     *
     * @param array $frequency
     * @return bool
     */
    protected function isFullHouse(array $frequency): bool
    {
        $counts = array_values($frequency);
        sort($counts);
        // Full house has exactly 2 groups: one with 3 dice, one with 3 dice (after 6 dice)
        // But actually it should be [2, 3] or [3, 2]
        return $counts === [2, 4] || $counts === [3, 3] || (count($counts) === 2 && in_array(3, $counts) && in_array(2, $counts));
    }

    /**
     * Determine if special die shows positive or negative
     *
     * Special die has 6 sides: 4 positive (+) and 2 negative (-)
     * Values 1-4 = positive, Values 5-6 = negative
     *
     * @param int $specialDieValue
     * @return bool
     */
    protected function isSpecialDiePositive(int $specialDieValue): bool
    {
        return $specialDieValue <= 4;
    }

    /**
     * {@inheritdoc}
     * Override to support turn-based completion
     */
    public function isRoundComplete(\App\Models\Game $game, int $roundNumber): bool
    {
        // In Roll Up, a round is complete when all players have completed their turn
        // (not just rolled once, but either banked or busted)
        $playerCount = $game->players()->count();

        // Count completed turns (where action_type = 'bank' or 'bust')
        $completedTurns = $game->rolls()
            ->where('round_number', $roundNumber)
            ->whereIn('action_type', ['bank', 'bust'])
            ->distinct('game_player_id')
            ->count('game_player_id');

        return $completedTurns >= $playerCount;
    }
}
