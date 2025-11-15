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
     * Roll dice - special die only rolls on FIRST roll of each turn, reused on re-rolls
     *
     * @param ProvablyFairService $provablyFair
     * @param Game $game
     * @param GamePlayer $player
     * @param int $roundNumber
     * @return array
     */
    public function rollDice(
        $provablyFair,
        $game,
        $player,
        int $roundNumber
    ): array {
        // Check if this is the first roll of this turn
        $rollCount = $player->rolls()
            ->where('round_number', $roundNumber)
            ->count();

        // First roll: Roll all 7 dice (6 regular + 1 special)
        if ($rollCount === 0) {
            return parent::rollDice($provablyFair, $game, $player, $roundNumber);
        }

        // Re-rolls: Roll only 6 regular dice, reuse special die from first roll
        $diceCount = 6; // Only regular dice
        $range = $this->getDiceRange();
        $dice = [];
        $nonces = [];

        // Calculate base nonce for this round
        $baseNonce = ($roundNumber - 1) * 7; // Still use 7 for nonce calculation

        // Roll 6 regular dice
        for ($i = 0; $i < $diceCount; $i++) {
            $nonce = $baseNonce + $i;
            $dice[] = $provablyFair->generateNumber(
                $game->server_seed,
                $player->client_seed,
                $nonce,
                $range['min'],
                $range['max']
            );
            $nonces[] = $nonce;
        }

        // Get special die value from first roll of this round
        $firstRoll = $player->rolls()
            ->where('round_number', $roundNumber)
            ->orderBy('id', 'asc')
            ->first();

        if ($firstRoll && isset($firstRoll->dice_values[6])) {
            $specialDie = $firstRoll->dice_values[6];
        } else {
            // Fallback: generate special die if first roll not found
            $specialDie = $provablyFair->generateNumber(
                $game->server_seed,
                $player->client_seed,
                6, // Nonce 6 from first roll
                $range['min'],
                $range['max']
            );
        }

        // Add special die as 7th die
        $dice[] = $specialDie;
        $nonces[] = $baseNonce + 6;

        return [
            'dice' => $dice,
            'nonces' => $nonces,
        ];
    }

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

        // Check if we need to include held dice for cumulative scoring
        $player = $context['player'] ?? null;
        $heldDice = [];

        if ($player && isset($player->turn_state['held_dice']) && !empty($player->turn_state['held_dice'])) {
            $heldDice = $player->turn_state['held_dice'];

            // Combine held dice with new roll for cumulative combo detection
            // This allows: hold three 2's, re-roll to get two 5's = Full House
            $allDice = array_merge($heldDice, $regularDice);

            // Detect combinations across ALL dice (held + new)
            $combinations = $this->detectCombinations($allDice);
        } else {
            // No held dice - just evaluate the current roll
            $combinations = $this->detectCombinations($regularDice);
        }

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
            'bonuses_applied' => $combosApplied, // Alias for backward compatibility
            'held_dice' => $heldDice, // Include held dice for context
        ];
    }

    /**
     * Detect all possible scoring combinations in the dice
     *
     * @param array $dice Array of 6 dice values
     * @return array Array of detected combinations with points and dice indices
     */
    protected function detectCombinations(array $dice): array
    {
        $combinations = [];

        // Count frequency of each die value and track their indices
        $frequency = array_count_values($dice);
        arsort($frequency); // Sort by frequency descending

        // Build a map of value => [indices]
        $valueToIndices = [];
        foreach ($dice as $index => $value) {
            $valueToIndices[$value][] = $index;
        }

        // Check for Large Straight (1-2-3-4-5-6)
        if ($this->isLargeStraight($dice)) {
            $combinations[] = [
                'name' => 'large_straight',
                'description' => 'Large Straight (1-2-3-4-5-6)',
                'points' => 1500,
                'dice_used' => $dice,
                'dice_indices' => [0, 1, 2, 3, 4, 5], // All dice
                'priority' => 1,
            ];
        }

        // Check for Small Straight (1-2-3-4-5)
        if ($this->isSmallStraight($dice)) {
            // Find indices of dice with values 1-5
            $straightIndices = [];
            foreach ([1, 2, 3, 4, 5] as $requiredValue) {
                if (isset($valueToIndices[$requiredValue])) {
                    $straightIndices[] = $valueToIndices[$requiredValue][0]; // Take first occurrence
                }
            }

            $combinations[] = [
                'name' => 'small_straight',
                'description' => 'Small Straight (1-2-3-4-5)',
                'points' => 500,
                'dice_used' => [1, 2, 3, 4, 5],
                'dice_indices' => $straightIndices,
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
                    'dice_indices' => $valueToIndices[$value], // All 6 indices
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
                    'dice_indices' => array_slice($valueToIndices[$value], 0, 5), // First 5 indices
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
                    'dice_indices' => array_slice($valueToIndices[$value], 0, 4), // First 4 indices
                    'priority' => 3,
                ];
            }
        }

        // Check for Full House (3 of one + 2 of another)
        if ($this->isFullHouse($frequency)) {
            // Find the value with 3 occurrences and the value with 2 occurrences
            $fullHouseIndices = [];
            foreach ($frequency as $value => $count) {
                if ($count === 3) {
                    $fullHouseIndices = array_merge($fullHouseIndices, array_slice($valueToIndices[$value], 0, 3));
                } elseif ($count === 2) {
                    $fullHouseIndices = array_merge($fullHouseIndices, array_slice($valueToIndices[$value], 0, 2));
                }
            }

            $combinations[] = [
                'name' => 'full_house',
                'description' => 'Full House',
                'points' => 300,
                'dice_used' => $dice,
                'dice_indices' => $fullHouseIndices, // All 5 dice in the full house
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
                    'dice_indices' => array_slice($valueToIndices[$value], 0, 3), // First 3 indices
                    'priority' => 4,
                ];
            }
        }

        // Check for individual 1s and 5s (Farkle-style scoring)
        // Individual 1s = 100 points each, Individual 5s = 50 points each
        // Only count 1s/5s that are NOT part of a larger combination
        $onesCount = $frequency[1] ?? 0;
        $fivesCount = $frequency[5] ?? 0;

        // Calculate individual 1s scoring (exclude those in combinations)
        if ($onesCount > 0 && $onesCount < 3) {
            // Individual 1s (not part of three-of-a-kind or better)
            $individualOnesPoints = $onesCount * 100;
            $combinations[] = [
                'name' => 'individual_ones',
                'description' => $onesCount === 1 ? 'Single 1' : "Two 1s",
                'points' => $individualOnesPoints,
                'dice_used' => array_fill(0, $onesCount, 1),
                'dice_indices' => array_slice($valueToIndices[1] ?? [], 0, $onesCount),
                'priority' => 5,
            ];
        }

        // Calculate individual 5s scoring (exclude those in combinations)
        if ($fivesCount > 0 && $fivesCount < 3) {
            // Individual 5s (not part of three-of-a-kind or better)
            $individualFivesPoints = $fivesCount * 50;
            $combinations[] = [
                'name' => 'individual_fives',
                'description' => $fivesCount === 1 ? 'Single 5' : "Two 5s",
                'points' => $individualFivesPoints,
                'dice_used' => array_fill(0, $fivesCount, 5),
                'dice_indices' => array_slice($valueToIndices[5] ?? [], 0, $fivesCount),
                'priority' => 6,
            ];
        }

        // Combination: Individual 1s + Individual 5s together
        if ($onesCount > 0 && $onesCount < 3 && $fivesCount > 0 && $fivesCount < 3) {
            $combinedPoints = ($onesCount * 100) + ($fivesCount * 50);
            $combinedIndices = array_merge(
                array_slice($valueToIndices[1] ?? [], 0, $onesCount),
                array_slice($valueToIndices[5] ?? [], 0, $fivesCount)
            );
            $combinations[] = [
                'name' => 'ones_and_fives',
                'description' => "{$onesCount} " . ($onesCount === 1 ? '1' : '1s') . " and {$fivesCount} " . ($fivesCount === 1 ? '5' : '5s'),
                'points' => $combinedPoints,
                'dice_used' => array_merge(array_fill(0, $onesCount, 1), array_fill(0, $fivesCount, 5)),
                'dice_indices' => $combinedIndices,
                'priority' => 5, // Same priority as individual ones
            ];
        }

        // If no special combination, use sum of all dice
        if (empty($combinations)) {
            $sum = array_sum($dice);
            $combinations[] = [
                'name' => 'sum',
                'description' => 'Sum of Dice',
                'points' => $sum,
                'dice_used' => $dice,
                'dice_indices' => [0, 1, 2, 3, 4, 5], // All dice
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

        // Full House: must have at least one group of 3 and one group of 2
        // Could also have extra single dice (e.g., [1,1,1,6,6,5] -> counts [1,2,3])
        $hasThree = in_array(3, $counts);
        $hasTwo = in_array(2, $counts);

        // Also check for special cases like four + two ([2,4]) or three + three ([3,3])
        $isSpecialFullHouse = $counts === [2, 4] || $counts === [3, 3];

        return ($hasThree && $hasTwo) || $isSpecialFullHouse;
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
