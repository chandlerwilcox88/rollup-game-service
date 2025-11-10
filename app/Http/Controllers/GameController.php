<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\GameService;
use App\Services\ProvablyFairService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Laravel\Lumen\Routing\Controller as BaseController;

class GameController extends BaseController
{
    public function __construct(
        private GameService $gameService,
        private ProvablyFairService $provablyFairService
    ) {}

    /**
     * Health check endpoint
     *
     * @return array
     */
    public function health(): array
    {
        return [
            'status' => 'ok',
            'service' => 'Roll Up Game Service',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Create a new game
     *
     * POST /api/games
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'room_code' => 'required|string|max:8',
            'players' => 'required|array|min:2|max:6',
            'players.*.id' => 'required|integer',
            'players.*.username' => 'required|string|max:255',
            'players.*.position' => 'required|integer|min:1|max:6',
            'players.*.client_seed' => 'nullable|string|max:64',
            'settings' => 'nullable|array',
            'settings.rounds' => 'nullable|integer|min:1|max:20',
            'settings.turn_time_limit' => 'nullable|integer|min:5|max:60',
        ]);

        try {
            $game = $this->gameService->createGame(
                $request->input('room_code'),
                $request->input('players'),
                $request->input('settings', [])
            );

            // Auto-start the game
            $game = $this->gameService->startGame($game);

            return response()->json([
                'success' => true,
                'message' => 'Game created successfully',
                'data' => $this->gameService->getGameState($game),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create game',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get game state
     *
     * GET /api/games/{gameId}
     *
     * @param string $gameId
     * @param Request $request
     * @return JsonResponse
     */
    public function show(string $gameId, Request $request): JsonResponse
    {
        try {
            $game = Game::findOrFail($gameId);
            $userId = $request->input('user_id'); // Optional, for hiding other players' rolls

            return response()->json([
                'success' => true,
                'data' => $this->gameService->getGameState($game, $userId),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Game not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Perform action (roll dice)
     *
     * POST /api/games/{gameId}/action
     *
     * @param string $gameId
     * @param Request $request
     * @return JsonResponse
     */
    public function action(string $gameId, Request $request): JsonResponse
    {
        $this->validate($request, [
            'user_id' => 'required|integer',
            'action' => 'required|string|in:roll',
        ]);

        try {
            $game = Game::findOrFail($gameId);

            if ($game->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Game is not in progress',
                ], 400);
            }

            $userId = $request->input('user_id');
            $action = $request->input('action');

            if ($action === 'roll') {
                $result = $this->gameService->rollDice($game, $userId);

                return response()->json([
                    'success' => true,
                    'message' => 'Dice rolled successfully',
                    'data' => [
                        'action' => 'roll',
                        'round' => $game->current_round,
                        ...$result,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid action',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Action failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get game results
     *
     * GET /api/games/{gameId}/results
     *
     * @param string $gameId
     * @return JsonResponse
     */
    public function results(string $gameId): JsonResponse
    {
        try {
            $game = Game::findOrFail($gameId);

            $results = $this->gameService->getGameResults($game);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Verify a roll
     *
     * POST /api/games/{gameId}/verify
     *
     * @param string $gameId
     * @param Request $request
     * @return JsonResponse
     */
    public function verify(string $gameId, Request $request): JsonResponse
    {
        $this->validate($request, [
            'user_id' => 'required|integer',
            'round_number' => 'required|integer|min:1',
        ]);

        try {
            $game = Game::findOrFail($gameId);

            $verification = $this->gameService->verifyRoll(
                $game,
                $request->input('user_id'),
                $request->input('round_number')
            );

            return response()->json([
                'success' => true,
                'data' => $verification,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get server seed hash (before game starts/during game)
     *
     * GET /api/games/{gameId}/server-seed-hash
     *
     * @param string $gameId
     * @return JsonResponse
     */
    public function serverSeedHash(string $gameId): JsonResponse
    {
        try {
            $game = Game::findOrFail($gameId);

            return response()->json([
                'success' => true,
                'data' => [
                    'game_id' => $game->id,
                    'server_seed_hash' => $game->server_seed_hash,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Game not found',
            ], 404);
        }
    }

    /**
     * Get server seed (only after game is completed)
     *
     * GET /api/games/{gameId}/server-seed
     *
     * @param string $gameId
     * @return JsonResponse
     */
    public function serverSeed(string $gameId): JsonResponse
    {
        try {
            $game = Game::findOrFail($gameId);

            if (!$game->isCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Server seed only revealed after game completion',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'game_id' => $game->id,
                    'server_seed' => $game->server_seed,
                    'server_seed_hash' => $game->server_seed_hash,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Game not found',
            ], 404);
        }
    }

    /**
     * Update client seed (before game starts)
     *
     * POST /api/games/{gameId}/update-seed
     *
     * @param string $gameId
     * @param Request $request
     * @return JsonResponse
     */
    public function updateSeed(string $gameId, Request $request): JsonResponse
    {
        $this->validate($request, [
            'user_id' => 'required|integer',
            'client_seed' => 'required|string|max:64',
        ]);

        try {
            $game = Game::findOrFail($gameId);

            if ($game->status !== 'waiting') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update seed after game has started',
                ], 400);
            }

            $player = $game->players()
                ->where('user_id', $request->input('user_id'))
                ->firstOrFail();

            $player->update([
                'client_seed' => $request->input('client_seed'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client seed updated successfully',
                'data' => [
                    'user_id' => $player->user_id,
                    'client_seed' => $player->client_seed,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * End game (admin/manual end)
     *
     * POST /api/games/{gameId}/end
     *
     * @param string $gameId
     * @return JsonResponse
     */
    public function end(string $gameId): JsonResponse
    {
        try {
            $game = Game::findOrFail($gameId);

            $game->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Game ended successfully',
                'data' => $this->gameService->getGameResults($game),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
