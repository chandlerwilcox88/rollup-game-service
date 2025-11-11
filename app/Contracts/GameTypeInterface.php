<?php

namespace App\Contracts;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\ProvablyFairService;

/**
 * GameTypeInterface
 *
 * Contract that all game types must implement.
 * Defines the core operations for different dice games.
 */
interface GameTypeInterface
{
    /**
     * Get the game type configuration
     *
     * @return array Configuration array from database
     */
    public function getConfig(): array;

    /**
     * Roll the dice for this game type
     *
     * @param ProvablyFairService $provablyFair
     * @param Game $game
     * @param GamePlayer $player
     * @param int $roundNumber
     * @return array ['dice' => [values], 'nonces' => [used nonces]]
     */
    public function rollDice(
        ProvablyFairService $provablyFair,
        Game $game,
        GamePlayer $player,
        int $roundNumber
    ): array;

    /**
     * Calculate the score for a roll
     *
     * @param array $diceValues e.g., [5, 3] or [1, 2, 3, 4, 5]
     * @param array $context Additional context (round, player, game state, etc.)
     * @return array [
     *   'dice' => array,
     *   'roll_total' => int,
     *   'bonus_points' => int,
     *   'total_points' => int,
     *   'bonuses_applied' => array (optional),
     * ]
     */
    public function calculateScore(array $diceValues, array $context = []): array;

    /**
     * Check if a round is complete
     *
     * @param Game $game
     * @param int $roundNumber
     * @return bool
     */
    public function isRoundComplete(Game $game, int $roundNumber): bool;

    /**
     * Check if the game is complete
     *
     * @param Game $game
     * @return bool
     */
    public function isGameComplete(Game $game): bool;

    /**
     * Get the winner(s) of the game
     *
     * @param Game $game
     * @return \Illuminate\Database\Eloquent\Collection|GamePlayer
     */
    public function getWinner(Game $game);

    /**
     * Validate game settings before creating a game
     *
     * @param array $settings
     * @return array Validated/normalized settings
     * @throws \InvalidArgumentException
     */
    public function validateSettings(array $settings): array;

    /**
     * Get allowed actions for this game type
     *
     * @return array e.g., ['roll'], ['roll', 'hold', 'challenge']
     */
    public function getAllowedActions(): array;

    /**
     * Get the number of dice for this game
     *
     * @return int
     */
    public function getDiceCount(): int;

    /**
     * Get dice range (min and max values)
     *
     * @return array ['min' => int, 'max' => int]
     */
    public function getDiceRange(): array;

    /**
     * Get player limits
     *
     * @return array ['min' => int, 'max' => int]
     */
    public function getPlayerLimits(): array;
}
