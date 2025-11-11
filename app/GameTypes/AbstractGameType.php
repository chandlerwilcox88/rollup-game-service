<?php

namespace App\GameTypes;

use App\Contracts\GameTypeInterface;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\ProvablyFairService;

/**
 * AbstractGameType
 *
 * Base class providing common functionality for all game types.
 * Individual game types should extend this and override specific methods.
 */
abstract class AbstractGameType implements GameTypeInterface
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * {@inheritdoc}
     */
    public function rollDice(
        ProvablyFairService $provablyFair,
        Game $game,
        GamePlayer $player,
        int $roundNumber
    ): array {
        $diceCount = $this->getDiceCount();
        $range = $this->getDiceRange();
        $dice = [];
        $nonces = [];

        // Calculate base nonce for this round
        $baseNonce = ($roundNumber - 1) * $diceCount;

        // Roll each die
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

        return [
            'dice' => $dice,
            'nonces' => $nonces,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isRoundComplete(Game $game, int $roundNumber): bool
    {
        // Default: round is complete when all players have rolled
        $playerCount = $game->players()->count();
        $rollsInRound = $game->rolls()
            ->where('round_number', $roundNumber)
            ->count();

        return $rollsInRound >= $playerCount;
    }

    /**
     * {@inheritdoc}
     */
    public function isGameComplete(Game $game): bool
    {
        // Default: game is complete when all rounds are done
        return $game->current_round > $game->total_rounds;
    }

    /**
     * {@inheritdoc}
     */
    public function getWinner(Game $game)
    {
        // Default: player with highest score wins
        return $game->players()
            ->orderBy('total_score', 'desc')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function validateSettings(array $settings): array
    {
        // Default validation: ensure rounds and time limit are reasonable
        $defaults = [
            'rounds' => data_get($this->config, 'rounds.default', 10),
            'turn_time_limit' => 15,
        ];

        $validated = array_merge($defaults, $settings);

        // Validate rounds
        $minRounds = data_get($this->config, 'rounds.min', 1);
        $maxRounds = data_get($this->config, 'rounds.max', 20);

        if ($validated['rounds'] < $minRounds || $validated['rounds'] > $maxRounds) {
            throw new \InvalidArgumentException(
                "Rounds must be between {$minRounds} and {$maxRounds}"
            );
        }

        return $validated;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedActions(): array
    {
        return data_get($this->config, 'actions', ['roll']);
    }

    /**
     * {@inheritdoc}
     */
    public function getDiceCount(): int
    {
        return data_get($this->config, 'dice.count', 2);
    }

    /**
     * {@inheritdoc}
     */
    public function getDiceRange(): array
    {
        return [
            'min' => data_get($this->config, 'dice.min', 1),
            'max' => data_get($this->config, 'dice.max', 6),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getPlayerLimits(): array
    {
        return [
            'min' => data_get($this->config, 'min_players', 2),
            'max' => data_get($this->config, 'max_players', 6),
        ];
    }

    /**
     * Helper: Get a config value with dot notation
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getConfigValue(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}
