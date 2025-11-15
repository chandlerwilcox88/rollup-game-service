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
use Illuminate\Support\Facades\Log;

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
        private GameTypeRegistry $gameTypeRegistry,
        private TurnStateManager $turnStateManager
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

        return $game->fresh(['players', 'gameType']);
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
        try {
            return DB::transaction(function () use ($game, $userId) {
                // Get the player
                $player = $game->players()->where('user_id', $userId)->firstOrFail();

                // Check if turn is already completed this round (banked or busted)
                if ($player->hasCompletedTurnInRound($game->current_round)) {
                    throw new \Exception('Player has already completed their turn in this round');
                }

                // Initialize turn state if this is the first roll of the round
                if (empty($player->turn_state)) {
                    $this->turnStateManager->initializeTurn($player);
                    $player->refresh(); // Reload to get the updated turn_state
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

            // Get the current roll sequence for this turn (how many times rolled in this round)
            $rollSequence = $player->rolls()
                ->where('round_number', $currentRound)
                ->count() + 1;

            // Idempotent check: Check if this roll already exists (browser refresh scenario)
            // IMPORTANT: Check BEFORE trying to create to avoid failed transaction state
            $existingRoll = PlayerRoll::where('game_id', $game->id)
                ->where('game_player_id', $player->id)
                ->where('round_number', $currentRound)
                ->where('roll_sequence', $rollSequence)
                ->first();

            if ($existingRoll) {
                // Duplicate roll detected - player likely refreshed browser
                // Return the existing roll without modifying database
                return [
                    'roll_id' => $existingRoll->id,
                    'dice' => $existingRoll->dice_values ?? [$existingRoll->dice1_value, $existingRoll->dice2_value],
                    'roll_total' => $existingRoll->roll_total,
                    'bonus_points' => $existingRoll->bonus_points,
                    'total_points' => $existingRoll->total_points,
                    'bonuses' => [], // Existing roll, no need to recalculate
                    'bonuses_applied' => [],
                    'bonus_descriptions' => [],
                    'player_total_score' => $player->fresh()->total_score,
                    'nonce' => $existingRoll->nonce,
                    'is_duplicate' => true, // Flag for frontend to know this was a refresh
                ];
            }

            // Prepare roll data for database
            $rollData = [
                'game_id' => $game->id,
                'game_player_id' => $player->id,
                'round_number' => $currentRound,
                'roll_sequence' => $rollSequence,
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

            // Create the roll (no duplicate possible due to check above)
            $roll = PlayerRoll::create($rollData);

            // Update player total score
            $player->increment('total_score', $scoreData['total_points']);

            // Update turn state after roll (enables hold/bank actions)
            $regularDice = array_slice($diceValues, 0, 6);  // Get just the 6 regular dice
            $turnState = $this->turnStateManager->afterRoll($player, $regularDice, $scoreData);

            // Don't check round completion after rolls - only after bank/bust

            // Format bonuses for backward compatibility
            $bonuses = array_map(fn($b) => $b['name'], $scoreData['bonuses_applied'] ?? []);
            $bonusDescriptions = array_map(fn($b) => "{$b['description']} (+{$b['points']} bonus)", $scoreData['bonuses_applied'] ?? []);

            // Convert best_combination to frontend-compatible combination format
            $combination = $this->formatCombinationForFrontend(
                $scoreData['best_combination'] ?? null,
                $regularDice
            );

            return [
                'roll_id' => $roll->id,
                'dice' => $diceValues,
                'regular_dice' => $regularDice,
                'special_die' => $diceValues[6] ?? null,
                'special_die_positive' => $scoreData['special_die_positive'] ?? true,
                'roll_total' => $scoreData['roll_total'],
                'bonus_points' => $scoreData['bonus_points'],
                'total_points' => $scoreData['total_points'],
                'bonuses' => $bonuses,
                'bonuses_applied' => $scoreData['bonuses_applied'] ?? [],
                'bonus_descriptions' => $bonusDescriptions,
                'player_total_score' => $player->fresh()->total_score,
                'nonce' => $baseNonce,
                'best_combination' => $scoreData['best_combination'] ?? null,
                'combination' => $combination,  // Frontend-compatible format
                'turn_state' => $turnState,  // Include turn state in response
            ];
            });
        } catch (\Exception $e) {
            // Log the error with context
            Log::error('Roll dice failed', [
                'game_id' => $game->id,
                'user_id' => $userId,
                'current_round' => $game->current_round,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Rethrow the exception to be handled by the controller
            throw $e;
        }
    }

    /**
     * Hold dice (Roll Up action)
     * Set aside scoring dice and update turn state
     *
     * @param Game $game
     * @param int $userId
     * @param array $heldDiceIndices Indices of dice to hold from available_dice
     * @return array
     */
    public function holdDice(Game $game, int $userId, array $heldDiceIndices): array
    {
        return DB::transaction(function () use ($game, $userId, $heldDiceIndices) {
            // Get the player (no turn check for simultaneous play)
            $player = $game->players()->where('user_id', $userId)->firstOrFail();

            // Get turn state
            $turnState = $this->turnStateManager->getTurnState($player);
            if (!($turnState['can_hold'] ?? false)) {
                throw new \Exception('Cannot hold dice at this time');
            }

            // Get available dice
            $availableDice = $turnState['available_dice'] ?? [];

            // Extract the dice values at the specified indices
            $heldDiceValues = [];
            foreach ($heldDiceIndices as $index) {
                if (!isset($availableDice[$index])) {
                    throw new \Exception("Invalid dice index: {$index}");
                }
                $heldDiceValues[] = $availableDice[$index];
            }

            // Validate that held dice form a scoring combo
            // TODO: Add more sophisticated validation using RollUpGameType
            if (empty($heldDiceValues)) {
                throw new \Exception('Must select at least one die to hold');
            }

            // Calculate score for held dice
            $gameTypeImpl = $this->gameTypeRegistry->getById($game->game_type_id);

            // For now, use a simplified scoring (just the best combo from those dice)
            // Pad to 7 dice for RollUpGameType (6 held + special die from last roll)
            $specialDie = $turnState['special_die'] ?? 1; // Default positive if not set
            $paddedDice = array_merge($heldDiceValues, array_fill(0, 6 - count($heldDiceValues), 0));
            $paddedDice[] = $specialDie;

            $scoreData = $gameTypeImpl->calculateScore(array_slice($paddedDice, 0, 7), [
                'game' => $game,
                'player' => $player,
                'evaluating_held_dice' => true,
            ]);

            $scoreFromHeldDice = $scoreData['best_combination']['points'];

            // Update turn state
            $updatedTurnState = $this->turnStateManager->afterHold(
                $player,
                $heldDiceValues,
                $heldDiceIndices,
                $scoreFromHeldDice
            );

            return [
                'held_dice' => $heldDiceValues,
                'score_added' => $scoreFromHeldDice,
                'pending_score' => $updatedTurnState['pending_score'],
                'turn_state' => $updatedTurnState,
            ];
        });
    }

    /**
     * Bank points (Roll Up action)
     * End turn and add pending score to player's total
     *
     * @param Game $game
     * @param int $userId
     * @param int|null $targetPlayerId For negative points assignment
     * @return array
     */
    public function bankPoints(Game $game, int $userId, ?int $targetPlayerId = null): array
    {
        return DB::transaction(function () use ($game, $userId, $targetPlayerId) {
            // Get the player (no turn check for simultaneous play)
            $player = $game->players()->where('user_id', $userId)->firstOrFail();

            // Get turn state
            $turnState = $this->turnStateManager->getTurnState($player);
            if (!($turnState['can_bank'] ?? false)) {
                throw new \Exception('Cannot bank at this time');
            }

            $pendingScore = $turnState['pending_score'] ?? 0;
            $specialDiePositive = $turnState['special_die_positive'] ?? true;

            // Determine where points go
            if ($specialDiePositive) {
                // Positive: add to current player
                $player->increment('total_score', $pendingScore);
                $targetPlayer = null;
            } else {
                // Negative: subtract from target player
                if (!$targetPlayerId) {
                    throw new \Exception('Must select a player to assign negative points');
                }

                $targetPlayer = $game->players()->where('user_id', $targetPlayerId)->firstOrFail();

                if ($targetPlayer->id === $player->id) {
                    throw new \Exception('Cannot assign negative points to yourself');
                }

                // Subtract from target (don't go below 0)
                $targetPlayer->total_score = max(0, $targetPlayer->total_score - $pendingScore);
                $targetPlayer->save();
            }

            // Save the roll as a "bank" action
            $rollData = [
                'game_id' => $game->id,
                'game_player_id' => $player->id,
                'round_number' => $game->current_round,
                'turn_number' => $turnState['turn_number'] ?? 1,
                'roll_sequence' => ($turnState['turn_roll_count'] ?? 0) + 1,
                'dice_values' => $turnState['held_dice'] ?? [],
                'held_dice' => $turnState['held_dice'] ?? [],
                'available_dice' => [],
                'nonce' => 0, // Bank action doesn't involve dice roll
                'dice1_value' => 0,
                'dice2_value' => 0,
                'roll_total' => $pendingScore,
                'bonus_points' => 0,
                'total_points' => $specialDiePositive ? $pendingScore : 0,
                'pending_score' => 0,
                'is_bust' => false,
                'action_type' => 'bank',
                'target_player_id' => $targetPlayer->id ?? null,
            ];

            PlayerRoll::create($rollData);

            // Mark turn as banked
            $this->turnStateManager->bankScore($player);

            // Check if round is complete (no need to advance - simultaneous play)
            $this->checkRoundCompletion($game);

            return [
                'banked_score' => $pendingScore,
                'special_die_positive' => $specialDiePositive,
                'target_player' => $targetPlayer ? [
                    'user_id' => $targetPlayer->user_id,
                    'username' => $targetPlayer->username,
                    'new_total_score' => $targetPlayer->total_score,
                ] : null,
                'player_total_score' => $player->fresh()->total_score,
            ];
        });
    }

    /**
     * Check if all players have completed their turns in current round and advance if needed
     *
     * @param Game $game
     * @return void
     */
    private function checkRoundCompletion(Game $game): void
    {
        $currentRound = $game->current_round;
        $totalPlayers = $game->players()->count();
        $playersWhoCompletedTurn = PlayerRoll::where('game_id', $game->id)
            ->where('round_number', $currentRound)
            ->whereIn('action_type', ['bank', 'bust'])
            ->distinct('game_player_id')
            ->count();

        if ($playersWhoCompletedTurn >= $totalPlayers) {
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
            $hasCompletedTurnThisRound = $player->hasCompletedTurnInRound($game->current_round);

            $playerData = [
                'id' => $player->id,
                'user_id' => $player->user_id,
                'username' => $player->username,
                'position' => $player->position,
                'total_score' => $player->total_score,
                'placement' => $player->placement,
                'status' => $player->status,
                'has_rolled_this_round' => $hasRolledThisRound,
                'has_completed_turn_this_round' => $hasCompletedTurnThisRound,
                'turn_state' => $player->turn_state,  // Each player has their own turn state
            ];

            // Only show roll details if:
            // 1. It's the requesting player's own roll, OR
            // 2. All players have rolled this round, OR
            // 3. Game is completed
            if ($game->isCompleted() || $player->user_id === $requestingUserId) {
                $roll = $player->getLatestRollForRound($game->current_round);
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
            // No current_player_id - simultaneous gameplay
            // turn_state is now per-player (in each player's data)
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

    /**
     * Convert best_combination to frontend-compatible combination format
     *
     * @param array|null $bestCombination The best_combination from scoring
     * @param array $regularDice The rolled dice values (6 dice, excluding special die)
     * @return array|null Formatted combination object or null
     */
    private function formatCombinationForFrontend(?array $bestCombination, array $regularDice): ?array
    {
        // Return null if no combination or if it's a sum/none combination
        if (!$bestCombination ||
            $bestCombination['name'] === 'sum' ||
            $bestCombination['name'] === 'none') {
            return null;
        }

        // Map combination names to material tiers
        $tierMap = [
            'six_of_a_kind' => 'diamond',
            'five_of_a_kind' => 'ruby',
            'large_straight' => 'emerald',
            'four_of_a_kind' => 'gold',
            'full_house' => 'rainbow',
            'small_straight' => 'crystal',
            'three_of_a_kind' => 'silver',
            'two_pairs' => 'bronze',
            'one_pair' => 'bronze',
        ];

        $tier = $tierMap[$bestCombination['name']] ?? 'wood';

        // Use the dice_indices from the combination (already calculated in detectCombinations)
        $diceIndices = $bestCombination['dice_indices'] ?? [];
        $diceUsed = $bestCombination['dice_used'] ?? [];

        // Extract the dice value (if applicable)
        $value = null;
        if (count($diceUsed) > 0) {
            // For combinations like "three of a kind", the value is the repeated number
            $value = $diceUsed[0];
        }

        return [
            'type' => $bestCombination['name'],
            'tier' => $tier,
            'dice_indices' => $diceIndices,
            'value' => $value,
            'display_name' => $bestCombination['description'],
        ];
    }
}
