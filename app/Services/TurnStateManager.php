<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;

/**
 * TurnStateManager
 *
 * Manages complex turn state for Roll Up gameplay where players
 * can take multiple actions (roll, hold, bank) within a single turn.
 */
class TurnStateManager
{
    /**
     * Initialize turn state for a new turn
     *
     * @param Game $game
     * @param GamePlayer $player
     * @return array
     */
    public function initializeTurn(Game $game, GamePlayer $player): array
    {
        $turnState = [
            'current_player_id' => $player->id,
            'held_dice' => [],
            'available_dice' => [],  // Will be populated after first roll
            'pending_score' => 0,
            'can_hold' => false,     // True after rolling
            'can_bank' => false,     // True after holding scoring dice
            'can_roll' => true,      // Initially true, false after bust
            'turn_roll_count' => 0,
        ];

        $game->update([
            'current_player_id' => $player->id,
            'turn_state' => $turnState,
        ]);

        return $turnState;
    }

    /**
     * Update turn state after a roll
     *
     * @param Game $game
     * @param array $diceResults 6 regular dice values
     * @param array $scoringResult Result from RollUpGameType::calculateScore()
     * @return array Updated turn state
     */
    public function afterRoll(Game $game, array $diceResults, array $scoringResult): array
    {
        $turnState = $game->turn_state ?? [];
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

        $game->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Update turn state after holding dice
     *
     * @param Game $game
     * @param array $heldDiceValues Values of dice being held
     * @param int $scoreFromHeldDice Points from the held dice
     * @return array Updated turn state
     */
    public function afterHold(Game $game, array $heldDiceValues, int $scoreFromHeldDice): array
    {
        $turnState = $game->turn_state ?? [];

        // Add held dice to the set
        $currentHeld = $turnState['held_dice'] ?? [];
        $turnState['held_dice'] = array_merge($currentHeld, $heldDiceValues);

        // Add to pending score
        $turnState['pending_score'] = ($turnState['pending_score'] ?? 0) + $scoreFromHeldDice;

        // Remove held dice from available
        $available = $turnState['available_dice'] ?? [];
        foreach ($heldDiceValues as $value) {
            $key = array_search($value, $available);
            if ($key !== false) {
                unset($available[$key]);
            }
        }
        $turnState['available_dice'] = array_values($available);

        // Update action availability
        $turnState['can_hold'] = false;
        $turnState['can_bank'] = true;   // Can now bank the pending score
        $turnState['can_roll'] = count($turnState['available_dice']) > 0; // Can roll if dice remain

        // Special case: If all 6 dice scored ("hot dice"), allow rolling all 6 again
        if (count($turnState['held_dice']) === 6 && count($turnState['available_dice']) === 0) {
            $turnState['available_dice'] = [];  // Will roll all 6 fresh dice
            $turnState['can_roll'] = true;
            $turnState['hot_dice'] = true;
        }

        $game->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Bank the pending score and end the turn
     *
     * @param Game $game
     * @return array Final turn state
     */
    public function bankScore(Game $game): array
    {
        $turnState = $game->turn_state ?? [];
        $turnState['is_banked'] = true;
        $turnState['can_hold'] = false;
        $turnState['can_bank'] = false;
        $turnState['can_roll'] = false;

        $game->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Handle a bust (no scoring dice on roll)
     *
     * @param Game $game
     * @return array Final turn state
     */
    public function bustTurn(Game $game): array
    {
        $turnState = $game->turn_state ?? [];
        $turnState['is_bust'] = true;
        $turnState['pending_score'] = 0;  // Lose all pending points
        $turnState['can_hold'] = false;
        $turnState['can_bank'] = false;
        $turnState['can_roll'] = false;

        $game->update(['turn_state' => $turnState]);

        return $turnState;
    }

    /**
     * Advance to the next player's turn
     *
     * @param Game $game
     * @return GamePlayer The next player
     */
    public function advanceToNextPlayer(Game $game): GamePlayer
    {
        $currentPlayer = $game->currentPlayer;
        $players = $game->players()->orderBy('position')->get();

        // Find current player index
        $currentIndex = $players->search(function ($player) use ($currentPlayer) {
            return $player->id === $currentPlayer->id;
        });

        // Get next player (wrap around to first if at end)
        $nextIndex = ($currentIndex + 1) % $players->count();
        $nextPlayer = $players[$nextIndex];

        // Initialize turn for next player
        $this->initializeTurn($game, $nextPlayer);

        return $nextPlayer;
    }

    /**
     * Check if turn is complete (either banked or busted)
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
     * @param Game $game
     * @return int Number of dice to roll
     */
    public function getDiceCountToRoll(Game $game): int
    {
        $turnState = $game->turn_state ?? [];
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
     * Clear turn state (at end of turn)
     *
     * @param Game $game
     * @return void
     */
    public function clearTurnState(Game $game): void
    {
        $game->update([
            'current_player_id' => null,
            'turn_state' => null,
        ]);
    }

    /**
     * Get current turn state
     *
     * @param Game $game
     * @return array|null
     */
    public function getTurnState(Game $game): ?array
    {
        return $game->turn_state;
    }
}
