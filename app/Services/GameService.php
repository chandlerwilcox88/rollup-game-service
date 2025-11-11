<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\PlayerRoll;
use App\Models\RoomGameHistory;
use App\Models\RoomPlayerStats;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Game Service
 *
 * Orchestrates all game logic including:
 * - Game creation
 * - Round management
 * - Dice rolling
 * - Score calculation
 * - Winner determination
 */
class GameService
{
    public function __construct(
        private ProvablyFairService $provablyFairService,
        private ScoringService $scoringService,
        private GameTypeRegistry $gameTypeRegistry
    ) {}

    /**
     * Create a new game
     *
     * @param string $roomCode
     * @param array $players
     * @param array $settings
     * @param string $gameTypeSlug Game type slug (defaults to 'roll-up' for backward compatibility)
     * @return Game
     */
    public function createGame(string $roomCode, array $players, array $settings = [], string $gameTypeSlug = 'roll-up'): Game
    {
        return DB::transaction(function () use ($roomCode, $players, $settings, $gameTypeSlug) {
            // Get game type
            $gameTypeImpl = $this->gameTypeRegistry->getBySlug($gameTypeSlug);
            $gameType = \App\Models\GameType::where('slug', $gameTypeSlug)->firstOrFail();

            // Validate settings against game type rules
            $validatedSettings = $gameTypeImpl->validateSettings($settings);

            // Generate provably fair server seed
            $serverSeed = $this->provablyFairService->generateServerSeed();
            $serverSeedHash = $this->provablyFairService->hashServerSeed($serverSeed);

            // Create game
            $game = Game::create([
                'id' => (string) Str::uuid(),
                'room_code' => $roomCode,
                'game_type_id' => $gameType->id,
                'server_seed' => $serverSeed,
                'server_seed_hash' => $serverSeedHash,
                'status' => 'waiting',
                'total_rounds' => $validatedSettings['rounds'],
                'turn_time_limit' => $validatedSettings['turn_time_limit'],
                'settings' => $validatedSettings,
            ]);

            // Create game players
            foreach ($players as $playerData) {
                $clientSeed = $playerData['client_seed'] ?? $this->provablyFairService->generateClientSeed();

                GamePlayer::create([
                    'game_id' => $game->id,
                    'user_id' => $playerData['id'],
                    'username' => $playerData['username'],
                    'position' => $playerData['position'],
                    'client_seed' => $clientSeed,
                ]);
            }

            return $game->fresh(['players', 'gameType']);
        });
    }

    /**
     * Start the game
     *
     * @param Game $game
     * @return Game
     */
    public function startGame(Game $game): Game
    {
        $game->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'current_round' => 1,
        ]);

        // Create first round
        $this->createRound($game, 1);

        return $game->fresh();
    }

    /**
     * Create a round for the game
     *
     * @param Game $game
     * @param int $roundNumber
     * @return GameRound
     */
    private function createRound(Game $game, int $roundNumber): GameRound
    {
        return GameRound::create([
            'game_id' => $game->id,
            'round_number' => $roundNumber,
            'status' => 'rolling',
            'started_at' => now(),
        ]);
    }

    /**
     * Roll dice for a player in the current round
     *
     * @param Game $game
     * @param int $userId
     * @return array Roll result with dice values and scores
     */
    public function rollDice(Game $game, int $userId): array
    {
        return DB::transaction(function () use ($game, $userId) {
            // Get the player
            $player = $game->players()->where('user_id', $userId)->firstOrFail();

            // Check if already rolled this round
            if ($player->hasRolledInRound($game->current_round)) {
                throw new \Exception('Player has already rolled in this round');
            }

            // Get game type implementation
            $gameTypeImpl = $this->gameTypeRegistry->getById($game->game_type_id);

            $currentRound = $game->current_round;

            // Roll dice using game type implementation
            $rollResult = $gameTypeImpl->rollDice(
                $this->provablyFairService,
                $game,
                $player,
                $currentRound
            );

            $diceValues = $rollResult['dice'];
            $nonces = $rollResult['nonces'];
            $baseNonce = $nonces[0]; // First nonce for verification

            // Calculate score using game type implementation
            $scoreData = $gameTypeImpl->calculateScore($diceValues, [
                'game' => $game,
                'player' => $player,
                'round' => $currentRound,
            ]);

            // Prepare roll data for database
            $rollData = [
                'game_id' => $game->id,
                'game_player_id' => $player->id,
                'round_number' => $currentRound,
                'nonce' => $baseNonce,
                'dice_values' => $diceValues,
                'roll_total' => $scoreData['roll_total'],
                'bonus_points' => $scoreData['bonus_points'],
                'total_points' => $scoreData['total_points'],
            ];

            // For backward compatibility with 2-dice games, also store in dice1/dice2
            if (count($diceValues) === 2) {
                $rollData['dice1_value'] = $diceValues[0];
                $rollData['dice2_value'] = $diceValues[1];
            }

            // Save roll
            $roll = PlayerRoll::create($rollData);

            // Update player total score
            $player->increment('total_score', $scoreData['total_points']);

            // Check if all players have rolled
            $this->checkRoundCompletion($game);

            // Format bonuses for backward compatibility
            $bonuses = array_map(fn($b) => $b['name'], $scoreData['bonuses_applied'] ?? []);
            $bonusDescriptions = array_map(fn($b) => "{$b['description']} (+{$b['points']} bonus)", $scoreData['bonuses_applied'] ?? []);

            return [
                'roll_id' => $roll->id,
                'dice' => $diceValues,
                'roll_total' => $scoreData['roll_total'],
                'bonus_points' => $scoreData['bonus_points'],
                'total_points' => $scoreData['total_points'],
                'bonuses' => $bonuses,
                'bonuses_applied' => $scoreData['bonuses_applied'],
                'bonus_descriptions' => $bonusDescriptions,
                'player_total_score' => $player->fresh()->total_score,
                'nonce' => $baseNonce,
            ];
        });
    }

    /**
     * Check if all players have rolled in current round and advance if needed
     *
     * @param Game $game
     * @return void
     */
    private function checkRoundCompletion(Game $game): void
    {
        $currentRound = $game->current_round;
        $totalPlayers = $game->players()->count();
        $playersWhoRolled = PlayerRoll::where('game_id', $game->id)
            ->where('round_number', $currentRound)
            ->distinct('game_player_id')
            ->count();

        if ($playersWhoRolled >= $totalPlayers) {
            // Mark round as completed
            GameRound::where('game_id', $game->id)
                ->where('round_number', $currentRound)
                ->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

            // Check if game is complete
            if ($currentRound >= $game->total_rounds) {
                $this->completeGame($game);
            } else {
                // Advance to next round
                $game->increment('current_round');
                $this->createRound($game, $game->current_round);
            }
        }
    }

    /**
     * Complete the game and determine placements
     *
     * @param Game $game
     * @return void
     */
    private function completeGame(Game $game): void
    {
        $game->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Determine placements
        $players = $game->players()
            ->select('id', 'user_id', 'username', 'total_score')
            ->get()
            ->map(function ($player) {
                return [
                    'id' => $player->id,
                    'user_id' => $player->user_id,
                    'username' => $player->username,
                    'total_score' => $player->total_score,
                ];
            })
            ->toArray();

        $playersWithPlacements = $this->scoringService->determinePlacements($players);

        // Update player placements
        foreach ($playersWithPlacements as $playerData) {
            GamePlayer::where('id', $playerData['id'])
                ->update(['placement' => $playerData['placement']]);
        }

        // Save to room history and update player stats
        $this->saveGameToRoomHistory($game, $playersWithPlacements);
    }

    /**
     * Save completed game to room history and update player stats
     *
     * @param Game $game
     * @param array $playersWithPlacements
     * @return void
     */
    private function saveGameToRoomHistory(Game $game, array $playersWithPlacements): void
    {
        // Find the winner (placement 1)
        $winner = collect($playersWithPlacements)->firstWhere('placement', 1);

        if (!$winner || !$game->room_code) {
            return; // Can't save history without a winner or room code
        }

        // Get next game number for this room
        $gameNumber = RoomGameHistory::getNextGameNumber($game->room_code);

        // Save game to history
        RoomGameHistory::create([
            'room_code' => $game->room_code,
            'game_id' => $game->id,
            'game_number' => $gameNumber,
            'winner_user_id' => $winner['user_id'],
            'winner_username' => $winner['username'],
            'total_rounds' => $game->total_rounds,
            'started_at' => $game->started_at,
            'completed_at' => $game->completed_at,
        ]);

        // Update stats for all players
        foreach ($playersWithPlacements as $player) {
            RoomPlayerStats::updatePlayerStats(
                $game->room_code,
                $player['user_id'],
                $player['username'],
                $player['total_score'],
                $player['placement']
            );
        }
    }

    /**
     * Get game state for API response
     *
     * @param Game $game
     * @param int|null $requestingUserId User ID requesting the state (to hide other players' rolls)
     * @return array
     */
    public function getGameState(Game $game, ?int $requestingUserId = null): array
    {
        $game->load(['players', 'rounds']);

        $players = $game->players->map(function ($player) use ($game, $requestingUserId) {
            $hasRolledThisRound = $player->hasRolledInRound($game->current_round);

            $playerData = [
                'id' => $player->id,
                'user_id' => $player->user_id,
                'username' => $player->username,
                'position' => $player->position,
                'total_score' => $player->total_score,
                'placement' => $player->placement,
                'status' => $player->status,
                'has_rolled_this_round' => $hasRolledThisRound,
            ];

            // Only show roll details if:
            // 1. It's the requesting player's own roll, OR
            // 2. All players have rolled this round, OR
            // 3. Game is completed
            if ($game->isCompleted() || $player->user_id === $requestingUserId) {
                $roll = $player->getRollForRound($game->current_round);
                if ($roll) {
                    $playerData['current_round_roll'] = [
                        'dice' => $roll->dice, // Uses getDiceAttribute() which is backward compatible
                        'total_points' => $roll->total_points,
                        'bonus_points' => $roll->bonus_points,
                    ];
                }
            }

            return $playerData;
        });

        return [
            'game_id' => $game->id,
            'room_code' => $game->room_code,
            'status' => $game->status,
            'current_round' => $game->current_round,
            'total_rounds' => $game->total_rounds,
            'turn_time_limit' => $game->turn_time_limit,
            'server_seed_hash' => $game->server_seed_hash,
            'started_at' => $game->started_at?->toIso8601String(),
            'completed_at' => $game->completed_at?->toIso8601String(),
            'players' => $players,
        ];
    }

    /**
     * Get game results (only available after game is completed)
     *
     * @param Game $game
     * @return array
     */
    public function getGameResults(Game $game): array
    {
        if (!$game->isCompleted()) {
            throw new \Exception('Game is not yet completed');
        }

        $game->load(['players.rolls']);

        $winner = $game->getWinner();

        $players = $game->players()
            ->orderBy('placement')
            ->get()
            ->map(function ($player) {
                return [
                    'user_id' => $player->user_id,
                    'username' => $player->username,
                    'total_score' => $player->total_score,
                    'placement' => $player->placement,
                    'rolls' => $player->rolls->map(function ($roll) {
                        return [
                            'round' => $roll->round_number,
                            'dice' => $roll->dice, // Uses getDiceAttribute() which is backward compatible
                            'total_points' => $roll->total_points,
                            'bonus_points' => $roll->bonus_points,
                        ];
                    }),
                ];
            });

        return [
            'game_id' => $game->id,
            'status' => $game->status,
            'winner' => [
                'user_id' => $winner->user_id,
                'username' => $winner->username,
                'total_score' => $winner->total_score,
                'placement' => $winner->placement,
            ],
            'players' => $players,
            'server_seed' => $game->server_seed, // Revealed after game
            'server_seed_hash' => $game->server_seed_hash,
        ];
    }

    /**
     * Verify a specific roll
     *
     * @param Game $game
     * @param int $userId
     * @param int $roundNumber
     * @return array
     */
    public function verifyRoll(Game $game, int $userId, int $roundNumber): array
    {
        if (!$game->isCompleted()) {
            throw new \Exception('Cannot verify rolls until game is completed');
        }

        $player = $game->players()->where('user_id', $userId)->firstOrFail();
        $roll = $player->rolls()->where('round_number', $roundNumber)->firstOrFail();

        // Get dice values (backward compatible)
        $diceValues = $roll->dice;
        $verifications = [];
        $allVerified = true;

        // Verify each die
        foreach ($diceValues as $index => $dieValue) {
            $nonce = $roll->nonce + $index;
            $verification = $this->provablyFairService->verify(
                $game->server_seed,
                $player->client_seed,
                $nonce,
                $dieValue
            );

            $verifications["dice" . ($index + 1)] = $verification;
            $allVerified = $allVerified && $verification['verified'];
        }

        return [
            'verified' => $allVerified,
            ...$verifications, // Spread dice1, dice2, etc.
            'roll' => [
                'round' => $roll->round_number,
                'dice' => $diceValues,
                'total_points' => $roll->total_points,
            ],
        ];
    }
}
