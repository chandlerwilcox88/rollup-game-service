# Game Types - Developer Guide

This guide explains how to add new dice game types to the Roll Up microservice.

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Step-by-Step Guide](#step-by-step-guide)
4. [GameType Interface Reference](#gametype-interface-reference)
5. [Example: Adding Yahtzee](#example-adding-yahtzee)
6. [Testing Your Game Type](#testing-your-game-type)

---

## Overview

The microservice uses a **Strategy Pattern** to support multiple game types. Each game type:

- Stores its configuration in the `game_types` database table (JSONB format)
- Implements the `GameTypeInterface` contract
- Extends `AbstractGameType` for common functionality
- Registers itself in the `GameTypeRegistry`

### Current Game Types

- **Roll Up** - Classic 2-dice game with bonus scoring (snake eyes, boxcars, doubles, seven)

### Planned Game Types

- **Yahtzee** - 5-dice game with categories (full house, straights, etc.)
- **Liar's Dice** - Bluffing game with bidding
- **Farkle** - Push-your-luck dice game

---

## Quick Start

To add a new game type, you need to:

1. Create a migration to insert the game type configuration
2. Create a GameType implementation class
3. Register the implementation in `GameTypeRegistry`
4. Test the new game type

**Time estimate**: 1-2 hours for a simple game type

---

## Step-by-Step Guide

### Step 1: Create Database Configuration

Create a migration to insert your game type into the `game_types` table:

```bash
php artisan make:migration add_yahtzee_game_type
```

**Migration Example** (`database/migrations/YYYY_MM_DD_HHMMSS_add_yahtzee_game_type.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('game_types')->insert([
            'slug' => 'yahtzee',
            'name' => 'Yahtzee',
            'description' => 'Classic 5-dice game with categories like full house, straights, and yahtzee',
            'is_active' => true,
            'config' => json_encode([
                'dice' => [
                    'count' => 5,
                    'min' => 1,
                    'max' => 6,
                ],
                'rounds' => [
                    'default' => 13, // 13 categories in Yahtzee
                    'min' => 13,
                    'max' => 13,
                ],
                'rolls_per_turn' => 3, // Players get 3 rolls per turn
                'scoring' => [
                    'categories' => [
                        ['name' => 'ones', 'description' => 'Sum of all ones', 'type' => 'number'],
                        ['name' => 'twos', 'description' => 'Sum of all twos', 'type' => 'number'],
                        // ... more categories
                        ['name' => 'yahtzee', 'description' => 'All 5 dice same', 'points' => 50],
                    ],
                ],
                'actions' => ['roll', 'hold', 'score'], // Players can roll, hold dice, or score
                'win_condition' => 'highest_score',
                'min_players' => 1,
                'max_players' => 6,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('game_types')->where('slug', 'yahtzee')->delete();
    }
};
```

Run the migration:

```bash
php artisan migrate
```

---

### Step 2: Create GameType Implementation

Create your game type class in `app/GameTypes/`:

```php
<?php

namespace App\GameTypes;

/**
 * YahtzeeGameType
 *
 * Implementation of Yahtzee dice game.
 *
 * Game Rules:
 * - 5 dice per roll
 * - 3 rolls per turn
 * - 13 categories to score
 * - Players can hold dice between rolls
 */
class YahtzeeGameType extends AbstractGameType
{
    /**
     * {@inheritdoc}
     */
    public function calculateScore(array $diceValues, array $context = []): array
    {
        // Yahtzee uses 5 dice
        if (count($diceValues) !== 5) {
            throw new \InvalidArgumentException('Yahtzee requires exactly 5 dice');
        }

        $category = $context['category'] ?? null;

        if (!$category) {
            // Just return the dice without scoring
            return [
                'dice' => $diceValues,
                'roll_total' => 0,
                'bonus_points' => 0,
                'total_points' => 0,
                'bonuses_applied' => [],
            ];
        }

        // Calculate score based on category
        $score = $this->calculateCategoryScore($diceValues, $category);

        return [
            'dice' => $diceValues,
            'category' => $category,
            'roll_total' => $score,
            'bonus_points' => 0,
            'total_points' => $score,
            'bonuses_applied' => [],
        ];
    }

    /**
     * Calculate score for a specific Yahtzee category
     */
    private function calculateCategoryScore(array $dice, string $category): int
    {
        $counts = array_count_values($dice);
        $sum = array_sum($dice);

        switch ($category) {
            case 'ones':
                return ($counts[1] ?? 0) * 1;

            case 'twos':
                return ($counts[2] ?? 0) * 2;

            // ... implement other categories

            case 'yahtzee':
                // All 5 dice must be the same
                return (count($counts) === 1) ? 50 : 0;

            case 'chance':
                // Sum of all dice
                return $sum;

            default:
                return 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateSettings(array $settings): array
    {
        // Yahtzee always has exactly 13 rounds
        $settings['rounds'] = 13;

        // Use parent validation for other settings
        return parent::validateSettings($settings);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedActions(): array
    {
        return ['roll', 'hold', 'score'];
    }
}
```

---

### Step 3: Register in GameTypeRegistry

Add your implementation to `app/Services/GameTypeRegistry.php`:

```php
private array $implementations = [
    'roll-up' => RollUpGameType::class,
    'yahtzee' => YahtzeeGameType::class, // Add this line
];
```

---

### Step 4: Test Your Game Type

Create a test file `tests/Feature/YahtzeeGameTest.php`:

```php
<?php

use App\Models\GameType;
use App\Services\GameTypeRegistry;

test('yahtzee game type is available', function () {
    $gameType = GameType::where('slug', 'yahtzee')->first();

    expect($gameType)->not->toBeNull();
    expect($gameType->name)->toBe('Yahtzee');
});

test('can create yahtzee game', function () {
    $response = $this->postJson('/api/games', [
        'room_code' => 'YAHTZEE1',
        'game_type' => 'yahtzee',
        'players' => [
            ['id' => 1, 'username' => 'Player1', 'position' => 1],
        ],
    ], [
        'X-API-Key' => env('API_KEY_OPENLUXE'),
    ]);

    $response->assertStatus(201);
    expect($response->json('data.total_rounds'))->toBe(13);
});

test('yahtzee rolls 5 dice', function () {
    // Create game and test rolling
    // ...
});
```

Run tests:

```bash
./vendor/bin/pest tests/Feature/YahtzeeGameTest.php
```

---

## GameType Interface Reference

All game types must implement `GameTypeInterface` with these methods:

### Core Methods

#### `rollDice()`
```php
public function rollDice(
    ProvablyFairService $provablyFair,
    Game $game,
    GamePlayer $player,
    int $roundNumber
): array;
```
**Purpose**: Roll the dice for a player.
**Returns**: `['dice' => [...], 'nonces' => [...]]`
**Note**: Default implementation in `AbstractGameType` handles standard dice rolling.

#### `calculateScore()`
```php
public function calculateScore(array $diceValues, array $context = []): array;
```
**Purpose**: Calculate the score for a roll.
**Returns**:
```php
[
    'dice' => [1, 2, 3],
    'roll_total' => 6,
    'bonus_points' => 0,
    'total_points' => 6,
    'bonuses_applied' => [],
]
```
**Note**: This is where you implement your game's scoring logic.

#### `validateSettings()`
```php
public function validateSettings(array $settings): array;
```
**Purpose**: Validate and normalize game settings.
**Returns**: Validated settings array.
**Throws**: `InvalidArgumentException` if validation fails.

### Helper Methods (Provided by AbstractGameType)

#### `getDiceCount()`
Returns the number of dice (from `config.dice.count`)

#### `getDiceRange()`
Returns `['min' => 1, 'max' => 6]` (from `config.dice.min/max`)

#### `getPlayerLimits()`
Returns `['min' => 2, 'max' => 6]` (from `config.min_players/max_players`)

#### `getAllowedActions()`
Returns allowed actions like `['roll']` or `['roll', 'hold', 'score']`

#### `isRoundComplete()`
Check if all players have completed their actions for the round

#### `isGameComplete()`
Check if the game is finished

#### `getWinner()`
Determine the winner (default: highest score)

---

## Example: Adding Yahtzee

Here's a minimal Yahtzee implementation:

### 1. Migration

```php
DB::table('game_types')->insert([
    'slug' => 'yahtzee',
    'name' => 'Yahtzee',
    'description' => 'Classic 5-dice category game',
    'is_active' => true,
    'config' => json_encode([
        'dice' => ['count' => 5, 'min' => 1, 'max' => 6],
        'rounds' => ['default' => 13, 'min' => 13, 'max' => 13],
        'actions' => ['roll', 'hold', 'score'],
        'min_players' => 1,
        'max_players' => 6,
    ]),
    'created_at' => now(),
    'updated_at' => now(),
]);
```

### 2. Implementation Class

```php
class YahtzeeGameType extends AbstractGameType
{
    public function calculateScore(array $diceValues, array $context = []): array
    {
        if (count($diceValues) !== 5) {
            throw new \InvalidArgumentException('Yahtzee requires 5 dice');
        }

        // Implement Yahtzee scoring logic here
        // For now, just return sum
        return [
            'dice' => $diceValues,
            'roll_total' => array_sum($diceValues),
            'bonus_points' => 0,
            'total_points' => array_sum($diceValues),
            'bonuses_applied' => [],
        ];
    }
}
```

### 3. Register

```php
// In GameTypeRegistry.php
private array $implementations = [
    'roll-up' => RollUpGameType::class,
    'yahtzee' => YahtzeeGameType::class,
];
```

### 4. Test

```bash
# Create a Yahtzee game
curl -X POST \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "room_code": "YAHTZEE1",
    "game_type": "yahtzee",
    "players": [{"id": 1, "username": "Player1", "position": 1}]
  }' \
  http://rollup-game-service.test/api/games
```

---

## Testing Your Game Type

### Manual Testing

1. **List available games**:
   ```bash
   curl -H "X-API-Key: KEY" http://rollup-game-service.test/api/game-types
   ```

2. **Create a game**:
   ```bash
   curl -X POST -H "X-API-Key: KEY" -H "Content-Type: application/json" \
     -d '{"room_code":"TEST","game_type":"yahtzee","players":[...]}' \
     http://rollup-game-service.test/api/games
   ```

3. **Roll dice**:
   ```bash
   curl -X POST -H "X-API-Key: KEY" -H "Content-Type: application/json" \
     -d '{"user_id":1,"action":"roll"}' \
     http://rollup-game-service.test/api/games/{gameId}/action
   ```

### Automated Testing

Create Pest tests in `tests/Feature/`:

```php
test('can create yahtzee game', function () {
    $response = $this->postJson('/api/games', [
        'room_code' => 'TEST',
        'game_type' => 'yahtzee',
        'players' => [['id' => 1, 'username' => 'Player1', 'position' => 1]],
    ], ['X-API-Key' => env('API_KEY_OPENLUXE')]);

    $response->assertStatus(201);
});

test('yahtzee rolls 5 dice', function () {
    // Test implementation
});

test('yahtzee scoring works correctly', function () {
    $gameType = app(GameTypeRegistry::class)->getBySlug('yahtzee');

    $result = $gameType->calculateScore([5, 5, 5, 5, 5], ['category' => 'yahtzee']);

    expect($result['total_points'])->toBe(50);
});
```

---

## Configuration Reference

### Required Config Fields

```json
{
  "dice": {
    "count": 2,      // Number of dice per roll
    "min": 1,        // Minimum die value
    "max": 6         // Maximum die value
  },
  "rounds": {
    "default": 10,   // Default number of rounds
    "min": 1,        // Minimum allowed rounds
    "max": 20        // Maximum allowed rounds
  },
  "actions": ["roll"], // Available actions
  "win_condition": "highest_score",
  "min_players": 2,  // Minimum players
  "max_players": 6   // Maximum players
}
```

### Optional Config Fields

```json
{
  "scoring": {
    // Game-specific scoring config
    "bonuses": [...],
    "categories": [...]
  },
  "rolls_per_turn": 3,  // For games like Yahtzee
  "custom_field": "any value"
}
```

---

## Best Practices

1. **Start Simple**: Implement basic functionality first, then add complexity
2. **Use Configuration**: Store game rules in the database config, not hardcoded
3. **Test Thoroughly**: Write tests for scoring, round completion, and winner determination
4. **Document Rules**: Add clear comments explaining game rules
5. **Provably Fair**: All randomness MUST use `ProvablyFairService`
6. **Backward Compatible**: Don't break existing games when adding new ones

---

## Common Patterns

### Multiple Rolls Per Turn (Yahtzee)

```php
// Track roll attempts in context
public function rollDice(...): array
{
    $rollAttempt = $context['roll_attempt'] ?? 1;

    // Player can roll up to 3 times
    if ($rollAttempt > 3) {
        throw new \Exception('Maximum rolls per turn exceeded');
    }

    // ... roll logic
}
```

### Holding Dice (Yahtzee)

```php
public function rollDice(...): array
{
    $heldDice = $context['held_dice'] ?? [];

    // Re-roll only non-held dice
    // ...
}
```

### Category-Based Scoring (Yahtzee)

```php
public function calculateScore(array $diceValues, array $context = []): array
{
    $category = $context['category'];
    $score = $this->calculateCategoryScore($diceValues, $category);

    // ...
}
```

---

## Need Help?

- Check existing implementations: `app/GameTypes/RollUpGameType.php`
- Review the interface: `app/Contracts/GameTypeInterface.php`
- See base class: `app/GameTypes/AbstractGameType.php`
- Contact: Chandler Wilcox

---

**Happy coding! 🎲**
