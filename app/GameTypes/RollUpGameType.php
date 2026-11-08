<?php

namespace App\GameTypes;

/**
 * RollUpGameType
 *
 * Roll Up: 6 regular dice (positions 0-5) + 1 special +/- die (position 6).
 *
 * Rules the server enforces (see GameService):
 * - Every player plays one turn per round; all players play the round at the
 *   same time. The round ends when every player has banked or busted.
 * - A turn starts by throwing all 7 dice. The special die is thrown ONCE per
 *   turn and keeps its face for the rest of the turn (1-4 = +, 5-6 = -).
 * - After each throw the player must HOLD at least one scoring die from that
 *   throw (every held die must score). The held dice's points go into the
 *   turn pot. Then the player may BANK the pot or ROLL the remaining dice.
 * - A throw with no scoring die is a BUST: the turn ends and the pot is lost.
 * - Hot dice: once all 6 regular dice are held, the next roll throws all 6 again
 *   (the pot is kept, the special die is not re-thrown).
 * - Bank on +: pot is added to the player's total. Bank on -: pot is
 *   subtracted from an opponent the player picks (never below 0).
 * - Scoring: single 1 = 100, single 5 = 50, three of a kind = value x 100
 *   (three 1s = 1000), four = value x 200, five = value x 500,
 *   six = value x 1000, small straight 1-5 = 500, large straight 1-6 = 1500,
 *   full house (3 + 2) = 300.
 * - Provably fair: die position p of a player's k-th throw in the game uses
 *   nonce k*7+p with HMAC-SHA256(serverSeed, "serverSeed:clientSeed:nonce").
 */
class RollUpGameType extends AbstractGameType
{
    /** Die positions 0-5 are the regular dice; position 6 is the +/- special die. */
    public const REGULAR_DICE = 6;
    public const SPECIAL_DIE_INDEX = 6;
    public const TOTAL_DICE = 7;

    /**
     * Every roll consumes a fixed block of 7 nonces, one per die position,
     * whether or not that position is thrown. The k-th dice roll a player makes
     * in a game (k = 0, 1, 2, ...) uses nonces k*7 .. k*7+6, and the die at
     * position p always uses nonce k*7+p. No nonce is ever reused, so no two
     * throws of a die can repeat by construction.
     */
    public const NONCE_BLOCK = 7;

    /**
     * Interface-compatible roll: throws all 7 dice for the first roll of a
     * player's game. The real Roll Up flow uses rollPositions(), which knows
     * which dice are held.
     */
    public function rollDice(
        $provablyFair,
        $game,
        $player,
        int $roundNumber
    ): array {
        $positions = range(0, self::TOTAL_DICE - 1);
        $thrown = $this->rollPositions($provablyFair, $game->server_seed, $player->client_seed, $positions, 0);

        return [
            'dice' => array_values($thrown['values']),
            'nonces' => array_values($thrown['nonces']),
        ];
    }

    /**
     * Throw the given die positions with nonces baseNonce + position.
     *
     * @param  int[]  $positions  die positions (0-6) to throw
     * @return array{values: array<int,int>, nonces: array<int,int>} keyed by position
     */
    public function rollPositions($provablyFair, string $serverSeed, string $clientSeed, array $positions, int $baseNonce): array
    {
        $range = $this->getDiceRange();
        $values = [];
        $nonces = [];

        foreach ($positions as $position) {
            $nonce = $baseNonce + $position;
            $values[$position] = $provablyFair->generateNumber($serverSeed, $clientSeed, $nonce, $range['min'], $range['max']);
            $nonces[$position] = $nonce;
        }

        return ['values' => $values, 'nonces' => $nonces];
    }

    /**
     * Score a set of dice the player wants to set aside.
     *
     * Every die in the set must contribute to a scoring combination; a set
     * containing a "dead" die (e.g. a lone 2) is not a legal hold and returns
     * null. Otherwise returns the best total over all ways of splitting the
     * set into combinations.
     *
     * @param  int[]  $values
     */
    public function scoreSelection(array $values): ?int
    {
        if (empty($values)) {
            return null;
        }

        $counts = array_fill(1, 6, 0);
        foreach ($values as $value) {
            $value = (int) $value;
            if ($value < 1 || $value > 6) {
                return null;
            }
            $counts[$value]++;
        }

        $memo = [];
        $best = $this->bestPartition($counts, $memo);

        return $best === null ? null : $best;
    }

    /**
     * Does this throw contain at least one scoring die? A throw with none is a bust.
     *
     * @param  int[]  $values
     */
    public function hasScoringDice(array $values): bool
    {
        return $this->bestHold($values) !== null;
    }

    /**
     * The most valuable legal hold available in a throw.
     *
     * @param  array<int,int>  $valuesByPosition  thrown dice keyed by position
     * @return array{indices: int[], values: int[], points: int}|null  null = bust
     */
    public function bestHold(array $valuesByPosition): ?array
    {
        $positions = array_keys($valuesByPosition);
        $n = count($positions);
        $best = null;

        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $indices = [];
            $values = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $indices[] = $positions[$i];
                    $values[] = $valuesByPosition[$positions[$i]];
                }
            }

            $points = $this->scoreSelection($values);
            if ($points === null) {
                continue;
            }

            if ($best === null
                || $points > $best['points']
                || ($points === $best['points'] && count($indices) > count($best['indices']))) {
                $best = ['indices' => $indices, 'values' => $values, 'points' => $points];
            }
        }

        return $best;
    }

    /**
     * Best total for a multiset of dice where every die must be used, or null.
     *
     * @param  array<int,int>  $counts  value => count
     */
    private function bestPartition(array $counts, array &$memo): ?int
    {
        $total = array_sum($counts);
        if ($total === 0) {
            return 0;
        }

        $key = implode(',', $counts);
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $best = null;
        $try = function (array $remaining, int $points) use (&$best, &$memo) {
            $rest = $this->bestPartition($remaining, $memo);
            if ($rest !== null && ($best === null || $points + $rest > $best)) {
                $best = $points + $rest;
            }
        };

        // Large straight 1-6
        if (min($counts) >= 1) {
            $next = $counts;
            foreach (range(1, 6) as $v) {
                $next[$v]--;
            }
            $try($next, 1500);
        }

        // Small straight 1-5
        if ($counts[1] && $counts[2] && $counts[3] && $counts[4] && $counts[5]) {
            $next = $counts;
            foreach (range(1, 5) as $v) {
                $next[$v]--;
            }
            $try($next, 500);
        }

        foreach (range(1, 6) as $v) {
            // N of a kind
            for ($n = 3; $n <= $counts[$v]; $n++) {
                $next = $counts;
                $next[$v] -= $n;
                $try($next, $this->ofAKindPoints($v, $n));
            }

            // Full house: three of v + two of another value
            if ($counts[$v] >= 3) {
                foreach (range(1, 6) as $w) {
                    if ($w !== $v && $counts[$w] >= 2) {
                        $next = $counts;
                        $next[$v] -= 3;
                        $next[$w] -= 2;
                        $try($next, 300);
                    }
                }
            }
        }

        // Single 1 = 100, single 5 = 50
        if ($counts[1] >= 1) {
            $next = $counts;
            $next[1]--;
            $try($next, 100);
        }
        if ($counts[5] >= 1) {
            $next = $counts;
            $next[5]--;
            $try($next, 50);
        }

        return $memo[$key] = $best;
    }

    private function ofAKindPoints(int $value, int $count): int
    {
        return match ($count) {
            3 => $value === 1 ? 1000 : $value * 100,
            4 => $value * 200,
            5 => $value * 500,
            default => $value * 1000,
        };
    }

    /**
     * Describe a throw for display: the headline combination among the thrown
     * dice (dice_indices are die POSITIONS), whether it busts, and the best hold.
     *
     * @param  array<int,int>  $valuesByPosition  thrown regular dice keyed by position
     */
    public function evaluateThrow(array $valuesByPosition): array
    {
        $positions = array_keys($valuesByPosition);
        $values = array_values($valuesByPosition);
        $bestHold = $this->bestHold($valuesByPosition);

        if ($bestHold === null) {
            return [
                'is_bust' => true,
                'best_combination' => [
                    'name' => 'none',
                    'description' => 'No scoring dice — bust',
                    'points' => 0,
                    'dice_used' => [],
                    'dice_indices' => [],
                ],
                'best_hold' => null,
            ];
        }

        $combo = $this->getBestCombination($this->detectCombinations($values));
        $combo['dice_indices'] = array_values(array_map(
            fn ($i) => $positions[$i],
            array_filter($combo['dice_indices'] ?? [], fn ($i) => isset($positions[$i]))
        ));
        unset($combo['priority']);

        return [
            'is_bust' => false,
            'best_combination' => $combo,
            'best_hold' => $bestHold,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Scores a full 7-dice throw (6 regular + special). Only the regular dice
     * score; held dice from earlier throws are NOT mixed in (they were already
     * scored when they were held).
     */
    public function calculateScore(array $diceValues, array $context = []): array
    {
        if (count($diceValues) !== 7) {
            throw new \InvalidArgumentException('Roll Up requires exactly 7 dice (6 regular + 1 special)');
        }

        $regularDice = array_slice($diceValues, 0, 6);
        $specialDie = $diceValues[6];
        $evaluation = $this->evaluateThrow($regularDice);
        $points = $evaluation['best_hold']['points'] ?? 0;

        return [
            'dice' => $diceValues,
            'regular_dice' => $regularDice,
            'special_die' => $specialDie,
            'special_die_positive' => $this->isSpecialDiePositive($specialDie),
            'roll_total' => $points,
            'bonus_points' => 0,
            'total_points' => $points,
            'is_bust' => $evaluation['is_bust'],
            'best_combination' => $evaluation['best_combination'],
            'best_hold' => $evaluation['best_hold'],
            'bonuses_applied' => [],
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
                'dice_indices' => array_keys($dice),
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
        // Strictly three of one value plus two of another (5 dice), 300 points.
        $counts = array_values($frequency);

        return in_array(3, $counts, true) && in_array(2, $counts, true);
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
    public function isSpecialDiePositive(int $specialDieValue): bool
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
