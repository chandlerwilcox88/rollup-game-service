<?php

namespace App\Services;

use App\Models\GamePlayer;

/**
 * TurnStateManager
 *
 * Manages per-player turn state for Roll Up simultaneous gameplay.
 * Each player maintains their own turn state independently.
 */
class TurnStateManager
{
    /**
     * Initialize turn state for a player's round
     *
     * @param GamePlayer $player
     * @return array
     */
    public function initializeTurn(GamePlayer $player): array
    {
        $turnState = [
            'held_dice' => [],
            'held_dice_indices' => [],
            'available_dice' => [],  // Will be populated after first roll
            'pending_score' => 0,
            'can_hold' => false,     // True after rolling
            'can_bank' => false,     // True after holding scoring dice
            'can_roll' => true,      // Initially true, false after bust
            'turn_roll_count' => 0,
            'is_bust' => false,
            'is_banked' => false,
        ];

        $player->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Update turn state after a roll
     *
     * @param GamePlayer $player
     * @param array $diceResults 6 regular dice values
     * @param array $scoringResult Result from RollUpGameType::calculateScore()
     * @return array Updated turn state
     */
    public function afterRoll(GamePlayer $player, array $diceResults, array $scoringResult): array
    {
        $turnState = $player->turn_state ?? [];
        $turnState['turn_roll_count'] = ($turnState['turn_roll_count'] ?? 0) + 1;
        $turnState['available_dice'] = $diceResults;

        // Check if player rolled a scoring combination
        $hasScoringCombo = $scoringResult['best_combination']['name'] !== 'sum' &&
                           $scoringResult['best_combination']['name'] !== 'none';

        if ($hasScoringCombo) {
            // Player rolled a scoring combo - can hold dice
            $turnState['can_hold'] = true;
            $turnState['can_bank'] = false;  // Must hold first
            $turnState['can_roll'] = false;  // Must hold or bank first
        } else {
            // No scoring combo - this is a BUST
            $turnState['can_hold'] = false;
            $turnState['can_bank'] = false;
            $turnState['can_roll'] = false;
            $turnState['is_bust'] = true;
        }

        $player->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Update turn state after holding dice
     *
     * @param GamePlayer $player
     * @param array $heldDiceValues Values of dice being held
     * @param array $heldDiceIndices Indices of dice being held
     * @param int $scoreFromHeldDice Points from the held dice
     * @return array Updated turn state
     */
    public function afterHold(GamePlayer $player, array $heldDiceValues, array $heldDiceIndices, int $scoreFromHeldDice): array
    {
        $turnState = $player->turn_state ?? [];

        // Add held dice to the set
        $currentHeld = $turnState['held_dice'] ?? [];
        $currentHeldIndices = $turnState['held_dice_indices'] ?? [];

        $turnState['held_dice'] = array_merge($currentHeld, $heldDiceValues);
        $turnState['held_dice_indices'] = array_merge($currentHeldIndices, $heldDiceIndices);

        // Add to pending score
        $turnState['pending_score'] = ($turnState['pending_score'] ?? 0) + $scoreFromHeldDice;

        // Remove held dice from available
        $available = $turnState['available_dice'] ?? [];
        foreach ($heldDiceIndices as $index) {
            if (isset($available[$index])) {
                unset($available[$index]);
            }
        }
        $turnState['available_dice'] = array_values($available);

        // Update action availability
        $turnState['can_hold'] = false;
        $turnState['can_bank'] = true;   // Can now bank the pending score
        $turnState['can_roll'] = count($turnState['available_dice']) > 0; // Can roll if dice remain

        // Special case: If all 6 dice scored ("hot dice"), allow rolling all 6 again
        if (count($turnState['held_dice']) >= 6 && count($turnState['available_dice']) === 0) {
            $turnState['available_dice'] = [];  // Will roll all 6 fresh dice
            $turnState['can_roll'] = true;
            $turnState['hot_dice'] = true;
        }

        $player->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Bank the pending score and end the turn
     *
     * @param GamePlayer $player
     * @return array Final turn state
     */
    public function bankScore(GamePlayer $player): array
    {
        $turnState = $player->turn_state ?? [];
        $turnState['is_banked'] = true;
        $turnState['can_hold'] = false;
        $turnState['can_bank'] = false;
        $turnState['can_roll'] = false;

        $player->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Handle a bust (no scoring dice on roll)
     *
     * @param GamePlayer $player
     * @return array Final turn state
     */
    public function bustTurn(GamePlayer $player): array
    {
        $turnState = $player->turn_state ?? [];
        $turnState['is_bust'] = true;
        $turnState['pending_score'] = 0;  // Lose all pending points
        $turnState['can_hold'] = false;
        $turnState['can_bank'] = false;
        $turnState['can_roll'] = false;

        $player->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Check if player's turn is complete (either banked or busted)
     *
     * @param array $turnState
     * @return bool
     */
    public function isTurnComplete(array $turnState): bool
    {
        return ($turnState['is_banked'] ?? false) || ($turnState['is_bust'] ?? false);
    }

    /**
     * Get number of dice to roll (based on available dice)
     *
     * @param GamePlayer $player
     * @return int Number of dice to roll
     */
    public function getDiceCountToRoll(GamePlayer $player): int
    {
        $turnState = $player->turn_state ?? [];
        $availableDice = $turnState['available_dice'] ?? [];

        // If no dice available but can roll (hot dice scenario), roll all 6
        if (empty($availableDice) && ($turnState['can_roll'] ?? false)) {
            return 6;
        }

        // Otherwise, roll the available dice
        return count($availableDice);
    }

    /**
     * Validate that selected dice can be held (are a valid scoring combo)
     *
     * @param array $selectedDice
     * @param array $availableDice
     * @return bool
     */
    public function canHoldDice(array $selectedDice, array $availableDice): bool
    {
        // Check all selected dice are in available dice
        $availableCopy = $availableDice;
        foreach ($selectedDice as $die) {
            $key = array_search($die, $availableCopy);
            if ($key === false) {
                return false;  // Die not available
            }
            unset($availableCopy[$key]);
        }

        // TODO: Add validation that selected dice form a valid scoring combo
        // For now, just check they're all available
        return true;
    }

    /**
     * Clear turn state (at end of round)
     *
     * @param GamePlayer $player
     * @return void
     */
    public function clearTurnState(GamePlayer $player): void
    {
        $player->update(['turn_state' => null]);
    }

    /**
     * Get current turn state for a player
     *
     * @param GamePlayer $player
     * @return array|null
     */
    public function getTurnState(GamePlayer $player): ?array
    {
        return $player->turn_state;
    }
}
