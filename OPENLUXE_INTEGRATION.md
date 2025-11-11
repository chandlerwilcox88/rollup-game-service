# OpenLuxe Integration Guide

This guide explains how to integrate the Roll Up Game Service into the OpenLuxe platform.

## Setup

### 1. Environment Variables

Add to your OpenLuxe `.env` file:

```env
ROLLUP_MICROSERVICE_URL=http://rollup-game-service.test
ROLLUP_MICROSERVICE_API_KEY=J6GyGI5U8f165xk7gKCBL94pzA3DXSd6MUt+Rf1j5So=
```

For production:
```env
ROLLUP_MICROSERVICE_URL=https://rollup.laravel.cloud
ROLLUP_MICROSERVICE_API_KEY=<your-production-api-key>
```

### 2. API Key

The API key for Roll Up service is:
```
J6GyGI5U8f165xk7gKCBL94pzA3DXSd6MUt+Rf1j5So=
```

**Security**: Keep this key secure! Only use it in server-side code, never expose it to the client.

---

## Making API Requests

### Using Laravel HTTP Client

```php
use Illuminate\Support\Facades\Http;

// Create a game (defaults to Roll Up)
$response = Http::withHeaders([
    'X-API-Key' => config('services.rollup.api_key'),
])->post(config('services.rollup.url') . '/api/games', [
    'room_code' => $room->code,
    'game_type' => 'roll-up', // Optional, defaults to 'roll-up'
    'players' => [
        ['id' => 1, 'username' => 'Player 1', 'position' => 1],
        ['id' => 2, 'username' => 'Player 2', 'position' => 2],
    ],
    'settings' => [
        'rounds' => 10,
        'turn_time_limit' => 15,
    ],
]);

$game = $response->json();
```

### Using cURL

```bash
curl -X POST \
  -H "X-API-Key: J6GyGI5U8f165xk7gKCBL94pzA3DXSd6MUt+Rf1j5So=" \
  -H "Content-Type: application/json" \
  -d '{
    "room_code": "ABC123",
    "game_type": "roll-up",
    "players": [
      {"id": 1, "username": "player1", "position": 1},
      {"id": 2, "username": "player2", "position": 2}
    ]
  }' \
  http://rollup-game-service.test/api/games
```

---

## Game Types

The microservice now supports multiple game types. You can discover available games and their configurations.

### List Available Game Types

```php
$response = $this->client()->get('/api/game-types');
$gameTypes = $response->json()['data'];

// Returns:
// [
//   {
//     "id": 1,
//     "slug": "roll-up",
//     "name": "Roll Up",
//     "description": "Classic 2-dice game...",
//     "config": {
//       "dice": {"count": 2, "min": 1, "max": 6},
//       "player_limits": {"min": 2, "max": 6},
//       "actions": ["roll"]
//     }
//   }
// ]
```

### Get Specific Game Type

```php
$response = $this->client()->get('/api/game-types/roll-up');
$gameType = $response->json()['data'];
```

### Create Game with Specific Type

```php
// Create a Roll Up game (default)
$game = $this->createGame($roomCode, $players, [
    'rounds' => 10,
]);

// When other games are added (e.g., Yahtzee), specify the type:
$game = $this->createGame($roomCode, $players, [
    'rounds' => 10,
], 'yahtzee'); // Future: specify game type
```

---

## Complete Integration Example

### 1. Config File

Create `config/services.php` entry:

```php
'rollup' => [
    'url' => env('ROLLUP_MICROSERVICE_URL', 'http://rollup-game-service.test'),
    'api_key' => env('ROLLUP_MICROSERVICE_API_KEY'),
],
```

### 2. Service Class

Create `app/Services/RollUpService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RollUpService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.rollup.url');
        $this->apiKey = config('services.rollup.api_key');
    }

    /**
     * Create HTTP client with authentication
     */
    private function client()
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
        ])->baseUrl($this->baseUrl);
    }

    /**
     * Get available game types
     */
    public function getGameTypes()
    {
        $response = $this->client()->get('/api/game-types');

        return $response->json();
    }

    /**
     * Get a specific game type
     */
    public function getGameType(string $slug)
    {
        $response = $this->client()->get("/api/game-types/{$slug}");

        return $response->json();
    }

    /**
     * Create a new game
     */
    public function createGame(string $roomCode, array $players, array $settings = [], ?string $gameType = null)
    {
        $data = [
            'room_code' => $roomCode,
            'players' => $players,
            'settings' => array_merge([
                'rounds' => 10,
                'turn_time_limit' => 15,
            ], $settings),
        ];

        // Add game type if specified (defaults to 'roll-up' on server)
        if ($gameType) {
            $data['game_type'] = $gameType;
        }

        $response = $this->client()->post('/api/games', $data);

        return $response->json();
    }

    /**
     * Get game state
     */
    public function getGameState(string $gameId, ?int $userId = null)
    {
        $response = $this->client()->get("/api/games/{$gameId}", [
            'user_id' => $userId,
        ]);

        return $response->json();
    }

    /**
     * Roll dice for a player
     */
    public function rollDice(string $gameId, int $userId)
    {
        $response = $this->client()->post("/api/games/{$gameId}/action", [
            'user_id' => $userId,
            'action' => 'roll',
        ]);

        return $response->json();
    }

    /**
     * Get game results
     */
    public function getGameResults(string $gameId)
    {
        $response = $this->client()->get("/api/games/{$gameId}/results");

        return $response->json();
    }

    /**
     * Verify a roll (for provably fair verification)
     */
    public function verifyRoll(string $gameId, int $userId, int $roundNumber)
    {
        $response = $this->client()->post("/api/games/{$gameId}/verify", [
            'user_id' => $userId,
            'round_number' => $roundNumber,
        ]);

        return $response->json();
    }

    /**
     * Health check
     */
    public function healthCheck()
    {
        $response = $this->client()->get('/api/health');

        return $response->json();
    }
}
```

### 3. Usage in Controller

```php
<?php

namespace App\Http\Controllers;

use App\Services\RollUpService;
use Illuminate\Http\Request;

class GameRoomController extends Controller
{
    public function __construct(
        private RollUpService $rollUpService
    ) {}

    /**
     * Start a new game
     */
    public function startGame(Request $request)
    {
        $room = $request->user()->room;

        // Get players in the room
        $players = $room->players->map(function ($player, $index) {
            return [
                'id' => $player->id,
                'username' => $player->username,
                'position' => $index + 1,
                // Optional: Let players provide custom seed
                'client_seed' => $player->client_seed ?? null,
            ];
        })->toArray();

        // Create game in microservice
        $game = $this->rollUpService->createGame(
            $room->code,
            $players,
            [
                'rounds' => $room->settings['rounds'] ?? 10,
                'turn_time_limit' => 15,
            ]
        );

        // Store game ID in your database
        $room->update(['game_id' => $game['data']['game_id']]);

        return response()->json($game);
    }

    /**
     * Roll dice
     */
    public function rollDice(Request $request)
    {
        $room = $request->user()->room;

        $result = $this->rollUpService->rollDice(
            $room->game_id,
            $request->user()->id
        );

        // Broadcast to other players via websockets
        broadcast(new GameRollEvent($room, $result));

        return response()->json($result);
    }

    /**
     * Get game results
     */
    public function getResults(Request $request)
    {
        $room = $request->user()->room;

        $results = $this->rollUpService->getGameResults($room->game_id);

        return response()->json($results);
    }
}
```

---

## API Endpoints Reference

### Game Type Discovery

#### List Game Types
```
GET /api/game-types
```
Returns all available game types with their configurations.

#### Get Game Type
```
GET /api/game-types/{slug}
```
Get detailed configuration for a specific game type (e.g., `/api/game-types/roll-up`).

### Game Management

#### Create Game
```
POST /api/games
Body: {
  "room_code": "ABC123",
  "game_type": "roll-up",  // Optional, defaults to 'roll-up'
  "players": [...],
  "settings": {...}
}
```

#### Get Game State
```
GET /api/games/{gameId}?user_id={userId}
```

#### Roll Dice
```
POST /api/games/{gameId}/action
Body: { "user_id": 1, "action": "roll" }
```

#### Get Results
```
GET /api/games/{gameId}/results
```

### Provably Fair Verification

#### Verify Roll
```
POST /api/games/{gameId}/verify
Body: { "user_id": 1, "round_number": 3 }
```

#### Get Server Seed Hash (Before Game)
```
GET /api/games/{gameId}/server-seed-hash
```

#### Get Server Seed (After Game)
```
GET /api/games/{gameId}/server-seed
```

---

## Security Notes

### Allowed Domains

The microservice only accepts requests from:
- `openluxe.test` (local development)
- `openluxe.co` (production)
- `www.openluxe.co`
- `localhost` (local testing)

### API Key Storage

✅ **DO**:
- Store API key in `.env` file
- Only use API key in server-side code
- Use HTTPS in production

❌ **DON'T**:
- Never expose API key to client-side JavaScript
- Never commit API key to version control
- Never share API key publicly

---

## Testing

### Health Check

```bash
curl -H "X-API-Key: J6GyGI5U8f165xk7gKCBL94pzA3DXSd6MUt+Rf1j5So=" \
     http://rollup-game-service.test/api/health
```

Expected response:
```json
{
  "status": "ok",
  "service": "Roll Up Game Service",
  "version": "1.0.0",
  "timestamp": "2025-11-10T08:00:00+00:00"
}
```

---

## Troubleshooting

### 401 Error - API Key Required
- Check that `X-API-Key` header is being sent
- Verify API key is correctly configured in `.env`

### 403 Error - Invalid API Key
- Verify the API key matches exactly (no extra spaces/characters)
- Check that you're using the correct API key for the environment

### 403 Error - Origin Not Allowed
- Ensure requests are coming from an allowed domain
- For local development, make sure `openluxe.test` is properly configured
- Check that the `Origin` or `Referer` header is being sent correctly

---

## Support

For issues or questions, contact Chandler Wilcox or file an issue at:
https://github.com/chandlerwilcox88/rollup-game-service/issues
