<?php

namespace App\GameTypes;

/**
 * RollUpGameType
 *
 * Implementation of the classic Roll Up dice game.
 *
 * Game Rules:
 * - 2 dice per roll (standard 6-sided)
 * - Base score: sum of dice
 * - Bonuses:
 *   - Snake Eyes (1+1): +10 points
 *   - Boxcars (6+6): +15 points
 *   - Doubles (any matching): +5 points
 *   - Seven (total = 7): +3 points
 */
class RollUpGameType extends AbstractGameType
{
    /**
     * {@inheritdoc}
     */
    public function calculateScore(array $diceValues, array $context = []): array
    {
        // Roll Up always uses 2 dice
        if (count($diceValues) !== 2) {
            throw new \InvalidArgumentException('Roll Up requires exactly 2 dice');
        }

        [$dice1, $dice2] = $diceValues;
        $rollTotal = $dice1 + $dice2;
        $bonusPoints = 0;
        $bonusesApplied = [];

        // Get bonus configuration from database
        $bonusConfig = $this->getConfigValue('scoring.bonuses', []);

        // Sort bonuses by priority (lower priority number = higher precedence)
        usort($bonusConfig, function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        // Process each bonus type
        foreach ($bonusConfig as $bonus) {
            $bonusName = $bonus['name'];
            $bonusValue = $bonus['points'];
            $applied = false;

            switch ($bonusName) {
                case 'snake_eyes':
                    // Both dice show 1
                    if ($dice1 === 1 && $dice2 === 1) {
                        $bonusPoints += $bonusValue;
                        $bonusesApplied[] = [
                            'name' => $bonusName,
                            'description' => $bonus['description'],
                            'points' => $bonusValue,
                        ];
                        $applied = true;
                    }
                    break;

                case 'boxcars':
                    // Both dice show 6
                    if ($dice1 === 6 && $dice2 === 6) {
                        $bonusPoints += $bonusValue;
                        $bonusesApplied[] = [
                            'name' => $bonusName,
                            'description' => $bonus['description'],
                            'points' => $bonusValue,
                        ];
                        $applied = true;
                    }
                    break;

                case 'doubles':
                    // Both dice show the same number (but not snake eyes or boxcars)
                    if ($dice1 === $dice2 && !$this->hasBonusApplied($bonusesApplied, ['snake_eyes', 'boxcars'])) {
                        $bonusPoints += $bonusValue;
                        $bonusesApplied[] = [
                            'name' => $bonusName,
                            'description' => $bonus['description'],
                            'points' => $bonusValue,
                        ];
                        $applied = true;
                    }
                    break;

                case 'seven':
                    // Dice total equals 7
                    if ($rollTotal === 7) {
                        $bonusPoints += $bonusValue;
                        $bonusesApplied[] = [
                            'name' => $bonusName,
                            'description' => $bonus['description'],
                            'points' => $bonusValue,
                        ];
                        $applied = true;
                    }
                    break;
            }
        }

        return [
            'dice' => $diceValues,
            'roll_total' => $rollTotal,
            'bonus_points' => $bonusPoints,
            'total_points' => $rollTotal + $bonusPoints,
            'bonuses_applied' => $bonusesApplied,
        ];
    }

    /**
     * Check if any of the specified bonuses have been applied
     *
     * @param array $bonusesApplied
     * @param array $bonusNames
     * @return bool
     */
    private function hasBonusApplied(array $bonusesApplied, array $bonusNames): bool
    {
        foreach ($bonusesApplied as $bonus) {
            if (in_array($bonus['name'], $bonusNames)) {
                return true;
            }
        }

        return false;
    }
}
