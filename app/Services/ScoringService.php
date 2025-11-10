<?php

namespace App\Services;

/**
 * Scoring Service
 *
 * Handles all score calculations for dice rolls including bonuses:
 * - Doubles (both dice same): +5 bonus points
 * - Seven (total = 7): +3 bonus points
 * - Snake Eyes (1+1): +10 bonus points
 * - Boxcars (6+6): +15 bonus points
 *
 * Note: Bonuses can stack (e.g., rolling 6+6 gives doubles bonus AND boxcars bonus)
 */
class ScoringService
{
    /**
     * Calculate score for a dice roll
     *
     * @param int $dice1 First die value (1-6)
     * @param int $dice2 Second die value (1-6)
     * @return array Score breakdown with bonuses
     */
    public function calculateScore(int $dice1, int $dice2): array
    {
        $rollTotal = $dice1 + $dice2;
        $bonusPoints = 0;
        $bonuses = [];

        // Check for snake eyes (1+1) - highest priority special combo
        if ($dice1 === 1 && $dice2 === 1) {
            $bonusPoints += 10;
            $bonuses[] = 'snake_eyes';
        }
        // Check for boxcars (6+6) - second highest priority special combo
        elseif ($dice1 === 6 && $dice2 === 6) {
            $bonusPoints += 15;
            $bonuses[] = 'boxcars';
        }
        // Check for any other doubles
        elseif ($dice1 === $dice2) {
            $bonusPoints += 5;
            $bonuses[] = 'doubles';
        }

        // Check for seven bonus (can't stack with doubles since 7 requires different dice)
        if ($rollTotal === 7) {
            $bonusPoints += 3;
            $bonuses[] = 'seven';
        }

        return [
            'dice' => [$dice1, $dice2],
            'roll_total' => $rollTotal,
            'bonus_points' => $bonusPoints,
            'total_points' => $rollTotal + $bonusPoints,
            'bonuses' => $bonuses,
        ];
    }

    /**
     * Get human-readable bonus descriptions
     *
     * @param array $bonuses Array of bonus identifiers
     * @return array Array of human-readable descriptions
     */
    public function getBonusDescriptions(array $bonuses): array
    {
        $descriptions = [
            'snake_eyes' => 'Snake Eyes! (+10 bonus)',
            'boxcars' => 'Boxcars! (+15 bonus)',
            'doubles' => 'Doubles (+5 bonus)',
            'seven' => 'Lucky Seven (+3 bonus)',
        ];

        return array_map(function ($bonus) use ($descriptions) {
            return $descriptions[$bonus] ?? $bonus;
        }, $bonuses);
    }

    /**
     * Calculate total score for multiple rolls
     *
     * @param array $rolls Array of roll data
     * @return int Total score
     */
    public function calculateTotalScore(array $rolls): int
    {
        return array_reduce($rolls, function ($total, $roll) {
            return $total + ($roll['total_points'] ?? 0);
        }, 0);
    }

    /**
     * Determine placements for all players
     *
     * @param array $players Array of players with total_score
     * @return array Players with placement assigned
     */
    public function determinePlacements(array $players): array
    {
        // Sort players by total_score descending
        usort($players, function ($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });

        // Assign placements (handling ties)
        $currentPlacement = 1;
        $previousScore = null;
        $sameScoreCount = 0;

        foreach ($players as $index => &$player) {
            if ($previousScore !== null && $player['total_score'] < $previousScore) {
                $currentPlacement = $index + 1;
                $sameScoreCount = 0;
            } elseif ($previousScore !== null && $player['total_score'] === $previousScore) {
                $sameScoreCount++;
            }

            $player['placement'] = $currentPlacement;
            $previousScore = $player['total_score'];
        }

        return $players;
    }

    /**
     * Get statistics for a set of rolls
     *
     * @param array $rolls Array of roll data
     * @return array Statistics
     */
    public function getStatistics(array $rolls): array
    {
        $totalRolls = count($rolls);
        $totalPoints = 0;
        $totalBonusPoints = 0;
        $bonusCounts = [
            'snake_eyes' => 0,
            'boxcars' => 0,
            'doubles' => 0,
            'seven' => 0,
        ];

        foreach ($rolls as $roll) {
            $totalPoints += $roll['total_points'] ?? 0;
            $totalBonusPoints += $roll['bonus_points'] ?? 0;

            foreach ($roll['bonuses'] ?? [] as $bonus) {
                if (isset($bonusCounts[$bonus])) {
                    $bonusCounts[$bonus]++;
                }
            }
        }

        return [
            'total_rolls' => $totalRolls,
            'total_points' => $totalPoints,
            'total_bonus_points' => $totalBonusPoints,
            'average_points_per_roll' => $totalRolls > 0 ? round($totalPoints / $totalRolls, 2) : 0,
            'bonus_counts' => $bonusCounts,
        ];
    }
}
