<?php

namespace App\Services;

use App\Exceptions\GameRuleException;
use App\GameTypes\RollUpGameType;
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
     * Start the game: round 1 and a fresh turn for every player.
     */
    public function startGame(Game $game): Game
    {
        return DB::transaction(function () use ($game) {
            $game->update([
                'status' => 'in_progress',
                'started_at' => now(),
                'current_round' => 1,
            ]);

            $this->createRound($game, 1);
            $this->turnStateManager->resetForRound($game->players()->get(), 1);

            return $game->fresh(['players', 'gameType']);
        });
    }

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
     * Lock the game and the acting player for the duration of an action, and
     * check the game is being played. Every action serialises on the game row,
     * so concurrent banks can never both miss the "last player finished" check.
     *
     * @return array{0: Game, 1: GamePlayer}
     */
    private function lockForAction(Game $game, int $userId): array
    {
        $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

        if ($game->status !== 'in_progress') {
            throw GameRuleException::conflict("Game is not in progress (status: {$game->status})", 'game_not_in_progress');
        }

        $player = $game->players()->where('user_id', $userId)->lockForUpdate()->first();
        if (!$player) {
            throw GameRuleException::notFound('Player not found in this game', 'player_not_in_game');
        }

        return [$game, $player];
    }

    private function assertTurnOpen(array $state, string $action): void
    {
        if ($state['is_banked'] ?? false) {
            throw GameRuleException::conflict(
                "Cannot {$action}: player has already completed their turn in this round (banked)",
                'turn_completed'
            );
        }

        if ($state['is_bust'] ?? false) {
            throw GameRuleException::conflict(
                "Cannot {$action}: player busted and has already completed their turn in this round",
                'turn_completed'
            );
        }
    }

    /**
     * Roll: throw every die that is not held (all six again on hot dice; the
     * special die only on the first roll of the turn). A throw with no scoring
     * die is a bust — the turn ends and the pot is lost. Rolling never changes
     * a player's total; only banking does.
     */
    public function rollDice(Game $game, int $userId): array
    {
        return DB::transaction(function () use ($game, $userId) {
            [$game, $player] = $this->lockForAction($game, $userId);
            $round = $game->current_round;
            $state = $this->turnStateManager->stateFor($player, $round);

            $this->assertTurnOpen($state, 'roll');
            if (!($state['can_roll'] ?? false)) {
                throw GameRuleException::conflict(
                    'Cannot roll: hold at least one scoring die from your last roll first',
                    'must_hold_first'
                );
            }

            /** @var RollUpGameType $rules */
            $rules = $this->gameTypeRegistry->getById($game->game_type_id);

            $positions = $this->turnStateManager->positionsToRoll($state);

            // The k-th throw this player makes in the game uses nonces k*7 .. k*7+6.
            $throwIndex = $player->rolls()->whereIn('action_type', ['roll', 'bust'])->count();
            $baseNonce = $throwIndex * RollUpGameType::NONCE_BLOCK;

            $thrown = $rules->rollPositions(
                $this->provablyFairService,
                $game->server_seed,
                $player->client_seed,
                $positions,
                $baseNonce
            );

            $dice = $state['dice'];
            foreach ($thrown['values'] as $position => $value) {
                $dice[$position] = $value;
            }

            $regularThrown = array_filter(
                $thrown['values'],
                fn ($position) => $position < RollUpGameType::REGULAR_DICE,
                ARRAY_FILTER_USE_KEY
            );
            $evaluation = $rules->evaluateThrow($regularThrown);
            $isBust = $evaluation['is_bust'];
            $specialPositive = $rules->isSpecialDiePositive($dice[RollUpGameType::SPECIAL_DIE_INDEX]);
            $potBefore = $state['pending_score'] ?? 0;

            $nonces = array_fill(0, RollUpGameType::TOTAL_DICE, null);
            foreach ($thrown['nonces'] as $position => $nonce) {
                $nonces[$position] = $nonce;
            }

            $roll = PlayerRoll::create([
                'game_id' => $game->id,
                'game_player_id' => $player->id,
                'round_number' => $round,
                'turn_number' => 1,
                'roll_sequence' => ($state['turn_roll_count'] ?? 0) + 1,
                'nonce' => $baseNonce,
                'nonces' => $nonces,
                'rolled_indices' => $positions,
                'dice_values' => $dice,
                // positions that stayed held through this throw
                'held_dice' => array_values(array_diff(range(0, RollUpGameType::REGULAR_DICE - 1), $positions)),
                'available_dice' => array_values($regularThrown),
                'roll_total' => $evaluation['best_hold']['points'] ?? 0,
                'bonus_points' => 0,
                'total_points' => 0, // a throw scores nothing until it is held and banked
                'pending_score' => $isBust ? 0 : $potBefore,
                'is_bust' => $isBust,
                'action_type' => $isBust ? 'bust' : 'roll',
            ]);

            $state = $this->turnStateManager->afterRoll($player, $state, $dice, $positions, $isBust, $specialPositive);

            $roundAdvanced = false;
            if ($isBust) {
                $roundAdvanced = $this->checkRoundCompletion($game);
            }

            $heldIndices = $state['held_indices'];
            $combination = $isBust ? null : $this->formatCombinationForFrontend($evaluation['best_combination']);

            return [
                'roll_id' => $roll->id,
                'roll_sequence' => $roll->roll_sequence,
                'dice' => $dice,
                'regular_dice' => array_slice($dice, 0, RollUpGameType::REGULAR_DICE),
                'special_die' => $dice[RollUpGameType::SPECIAL_DIE_INDEX],
                'special_die_positive' => $specialPositive,
                'rolled_indices' => $positions,
                'held_indices' => $heldIndices,
                'held' => array_map(
                    fn ($i) => in_array($i, $heldIndices, true),
                    range(0, RollUpGameType::TOTAL_DICE - 1)
                ),
                'is_bust' => $isBust,
                'pot_lost' => $isBust ? $potBefore : 0,
                'pending_score' => $state['pending_score'],
                'best_combination' => $evaluation['best_combination'],
                'combination' => $combination,
                'best_hold' => $evaluation['best_hold'],
                'roll_total' => $evaluation['best_hold']['points'] ?? 0,
                'bonus_points' => 0,
                'total_points' => 0,
                'bonuses' => [],
                'bonuses_applied' => [],
                'bonus_descriptions' => [],
                'player_total_score' => $player->fresh()->total_score,
                'nonce' => $baseNonce,
                'nonces' => $nonces,
                'fairness' => [
                    'algorithm' => 'HMAC-SHA256(key=server_seed, msg="server_seed:client_seed:nonce"), first 8 hex chars mod 6 + 1',
                    'server_seed_hash' => $game->server_seed_hash,
                    'client_seed' => $player->client_seed,
                    'base_nonce' => $baseNonce,
                    'nonces' => $nonces,
                ],
                'turn_state' => $state,
                'round_advanced' => $roundAdvanced,
                'game_status' => $game->fresh()->status,
            ];
        });
    }

    /**
     * Hold: set aside scoring dice from the last throw. Indices are die
     * POSITIONS (0-5). Every held die must score; the points go into the pot.
     */
    public function holdDice(Game $game, int $userId, array $heldDiceIndices): array
    {
        return DB::transaction(function () use ($game, $userId, $heldDiceIndices) {
            [$game, $player] = $this->lockForAction($game, $userId);
            $state = $this->turnStateManager->stateFor($player, $game->current_round);

            $this->assertTurnOpen($state, 'hold');
            if (!($state['can_hold'] ?? false)) {
                throw GameRuleException::conflict(
                    ($state['turn_roll_count'] ?? 0) === 0
                        ? 'Cannot hold dice at this time: roll first'
                        : 'Cannot hold dice at this time: you already held from this roll; roll again or bank',
                    'cannot_hold'
                );
            }

            if (empty($heldDiceIndices)) {
                throw GameRuleException::invalid('Must select at least one die to hold', 'empty_hold');
            }

            $indices = [];
            foreach ($heldDiceIndices as $index) {
                if (!is_int($index) && !(is_string($index) && ctype_digit($index))) {
                    throw GameRuleException::invalid("Invalid dice index: {$index}", 'invalid_index');
                }
                $index = (int) $index;
                if (in_array($index, $indices, true)) {
                    throw GameRuleException::invalid("Invalid dice index: {$index} selected twice", 'invalid_index');
                }
                if (!in_array($index, $state['available_indices'] ?? [], true)) {
                    throw GameRuleException::invalid(
                        "Invalid dice index: {$index} was not thrown in your last roll (only " .
                        implode(', ', $state['available_indices'] ?? []) . ' can be held)',
                        'invalid_index'
                    );
                }
                $indices[] = $index;
            }
            sort($indices);

            $values = array_map(fn ($i) => $state['dice'][$i], $indices);

            /** @var RollUpGameType $rules */
            $rules = $this->gameTypeRegistry->getById($game->game_type_id);
            $points = $rules->scoreSelection($values);

            if ($points === null) {
                throw GameRuleException::invalid(
                    'Invalid hold: every held die must be part of a scoring combination (got ' . implode(', ', $values) . ')',
                    'non_scoring_hold'
                );
            }

            $state = $this->turnStateManager->afterHold($player, $state, $indices, $points);

            return [
                'held_indices' => $indices,
                'held_dice' => $values,
                'score_added' => $points,
                'pending_score' => $state['pending_score'],
                'hot_dice' => $state['hot_dice'],
                'turn_state' => $state,
            ];
        });
    }

    /**
     * Bank: end the turn. On + the pot is added to the player's total exactly
     * once; on - it is subtracted from an opponent the player picks.
     */
    public function bankPoints(Game $game, int $userId, ?int $targetPlayerId = null): array
    {
        return DB::transaction(function () use ($game, $userId, $targetPlayerId) {
            [$game, $player] = $this->lockForAction($game, $userId);
            $state = $this->turnStateManager->stateFor($player, $game->current_round);

            $this->assertTurnOpen($state, 'bank');
            if (!($state['can_bank'] ?? false)) {
                throw GameRuleException::conflict(
                    ($state['turn_roll_count'] ?? 0) === 0
                        ? 'Cannot bank at this time: roll first'
                        : 'Cannot bank at this time: hold at least one scoring die from your last roll first',
                    'cannot_bank'
                );
            }

            $pot = (int) ($state['pending_score'] ?? 0);
            $positive = (bool) ($state['special_die_positive'] ?? true);
            $targetPlayer = null;

            if ($positive) {
                $player->increment('total_score', $pot);
            } else {
                if (!$targetPlayerId) {
                    throw GameRuleException::invalid(
                        'Must select a player to assign negative points (data.target_player_id)',
                        'target_required'
                    );
                }
                if ($targetPlayerId === $player->user_id) {
                    throw GameRuleException::invalid('Cannot assign negative points to yourself', 'target_self');
                }

                $targetPlayer = $game->players()->where('user_id', $targetPlayerId)->lockForUpdate()->first();
                if (!$targetPlayer) {
                    throw GameRuleException::invalid('Target player is not in this game', 'target_not_in_game');
                }

                $targetPlayer->total_score = max(0, $targetPlayer->total_score - $pot);
                $targetPlayer->save();
            }

            PlayerRoll::create([
                'game_id' => $game->id,
                'game_player_id' => $player->id,
                'round_number' => $game->current_round,
                'turn_number' => 1,
                'roll_sequence' => ($state['turn_roll_count'] ?? 0) + 1,
                'dice_values' => $state['dice'],
                'held_dice' => $state['held_indices'],
                'available_dice' => [],
                'nonce' => 0, // a bank throws no dice
                'roll_total' => $pot,
                'bonus_points' => 0,
                'total_points' => $positive ? $pot : -$pot,
                'pending_score' => 0,
                'is_bust' => false,
                'action_type' => 'bank',
                'target_player_id' => $targetPlayer?->id,
            ]);

            $state = $this->turnStateManager->afterBank($player, $state);
            $roundAdvanced = $this->checkRoundCompletion($game);

            return [
                'banked_score' => $pot,
                'special_die_positive' => $positive,
                'target_player' => $targetPlayer ? [
                    'user_id' => $targetPlayer->user_id,
                    'username' => $targetPlayer->username,
                    'new_total_score' => $targetPlayer->total_score,
                ] : null,
                'player_total_score' => $player->fresh()->total_score,
                'turn_state' => $roundAdvanced ? $player->fresh()->turn_state : $state,
                'round_advanced' => $roundAdvanced,
                'game_status' => $game->fresh()->status,
            ];
        });
    }

    /**
     * When every player has banked or busted this round, close the round and
     * either start the next one (fresh turn state for everyone) or end the game.
     *
     * @return bool true when the round closed
     */
    private function checkRoundCompletion(Game $game): bool
    {
        $currentRound = $game->current_round;
        $totalPlayers = $game->players()->count();
        $finished = PlayerRoll::where('game_id', $game->id)
            ->where('round_number', $currentRound)
            ->whereIn('action_type', ['bank', 'bust'])
            ->distinct()
            ->count('game_player_id');

        if ($finished < $totalPlayers) {
            return false;
        }

        GameRound::where('game_id', $game->id)
            ->where('round_number', $currentRound)
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

        if ($currentRound >= $game->total_rounds) {
            $this->completeGame($game);
        } else {
            $game->update(['current_round' => $currentRound + 1]);
            $this->createRound($game, $currentRound + 1);
            $this->turnStateManager->resetForRound($game->players()->get(), $currentRound + 1);
        }

        return true;
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
                        'roll_id' => $roll->id,
                        'action_type' => $roll->action_type,
                        'dice' => $roll->dice,
                        'rolled_indices' => $roll->rolled_indices,
                        'is_bust' => $roll->is_bust,
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
            throw GameRuleException::conflict('Game is not yet completed', 'game_not_completed');
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
                    'rolls' => $player->rolls->sortBy('id')->values()->map(function ($roll) {
                        return [
                            'round' => $roll->round_number,
                            'roll_sequence' => $roll->roll_sequence,
                            'action_type' => $roll->action_type,
                            'dice' => $roll->dice,
                            'rolled_indices' => $roll->rolled_indices,
                            'nonces' => $roll->nonces,
                            'is_bust' => $roll->is_bust,
                            'total_points' => $roll->total_points,
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
     * Verify every throw a player made in a round (after the server seed is
     * revealed at game end). Each thrown die is recomputed from
     * (server_seed, client_seed, nonce); held dice are carried from the throw
     * that produced them and are checked there. Also proves the revealed seed
     * matches the hash published before the first roll.
     */
    public function verifyRoll(Game $game, int $userId, int $roundNumber): array
    {
        if (!$game->isCompleted()) {
            throw GameRuleException::conflict('Cannot verify rolls until game is completed', 'game_not_completed');
        }

        $player = $game->players()->where('user_id', $userId)->first();
        if (!$player) {
            throw GameRuleException::notFound('Player not found in this game', 'player_not_in_game');
        }

        $throws = $player->rolls()
            ->where('round_number', $roundNumber)
            ->whereIn('action_type', ['roll', 'bust'])
            ->orderBy('roll_sequence')
            ->get();

        if ($throws->isEmpty()) {
            throw GameRuleException::notFound("No rolls for this player in round {$roundNumber}", 'no_rolls');
        }

        $seedMatchesHash = $this->provablyFairService->verifyServerSeedHash($game->server_seed, $game->server_seed_hash);
        $allVerified = $seedMatchesHash;
        $rolls = [];

        foreach ($throws as $throw) {
            $dice = $throw->dice_values;
            $positions = $throw->rolled_indices ?? array_keys($dice);
            $checks = [];

            foreach ($positions as $position) {
                $nonce = $throw->nonces[$position] ?? ($throw->nonce + $position);
                $check = $this->provablyFairService->verify(
                    $game->server_seed,
                    $player->client_seed,
                    $nonce,
                    (int) $dice[$position]
                );
                $checks[] = ['position' => $position] + $check;
                $allVerified = $allVerified && $check['verified'];
            }

            $rolls[] = [
                'roll_id' => $throw->id,
                'roll_sequence' => $throw->roll_sequence,
                'dice' => $dice,
                'rolled_indices' => $positions,
                'is_bust' => $throw->is_bust,
                'verified' => collect($checks)->every(fn ($c) => $c['verified']),
                'dice_checks' => $checks,
            ];
        }

        return [
            'verified' => $allVerified,
            'server_seed_matches_hash' => $seedMatchesHash,
            'server_seed' => $game->server_seed,
            'server_seed_hash' => $game->server_seed_hash,
            'client_seed' => $player->client_seed,
            'round' => $roundNumber,
            'rolls' => $rolls,
        ];
    }

    /**
     * Convert best_combination to frontend-compatible combination format
     *
     * @param array|null $bestCombination The best_combination from scoring
     * @return array|null Formatted combination object or null
     */
    private function formatCombinationForFrontend(?array $bestCombination): ?array
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
