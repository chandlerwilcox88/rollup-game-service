<?php

namespace App\Services;

use App\Contracts\GameTypeInterface;
use App\GameTypes\RollUpGameType;
use App\Models\GameType;

/**
 * GameTypeRegistry
 *
 * Central registry for managing game type implementations.
 * Maps game type slugs to their implementation classes.
 */
class GameTypeRegistry
{
    /**
     * Map of game type slugs to their implementation classes
     *
     * @var array<string, string>
     */
    private array $implementations = [
        'roll-up' => RollUpGameType::class,
        // Future game types will be added here:
        // 'yahtzee' => YahtzeeGameType::class,
        // 'liars-dice' => LiarsDiceGameType::class,
    ];

    /**
     * Cache of instantiated game type instances
     *
     * @var array<int, GameTypeInterface>
     */
    private array $instances = [];

    /**
     * Get a game type implementation by slug
     *
     * @param string $slug e.g., 'roll-up'
     * @return GameTypeInterface
     * @throws \InvalidArgumentException if game type not found
     */
    public function getBySlug(string $slug): GameTypeInterface
    {
        // Load from database
        $gameType = GameType::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->get($gameType);
    }

    /**
     * Get a game type implementation by ID
     *
     * @param int $gameTypeId
     * @return GameTypeInterface
     * @throws \InvalidArgumentException if game type not found
     */
    public function getById(int $gameTypeId): GameTypeInterface
    {
        // Check cache first
        if (isset($this->instances[$gameTypeId])) {
            return $this->instances[$gameTypeId];
        }

        // Load from database
        $gameType = GameType::findOrFail($gameTypeId);

        return $this->get($gameType);
    }

    /**
     * Get a game type implementation from a GameType model
     *
     * @param GameType $gameType
     * @return GameTypeInterface
     * @throws \RuntimeException if implementation class not found
     */
    public function get(GameType $gameType): GameTypeInterface
    {
        // Check cache first
        if (isset($this->instances[$gameType->id])) {
            return $this->instances[$gameType->id];
        }

        // Get implementation class
        $implementationClass = $this->implementations[$gameType->slug] ?? null;

        if (!$implementationClass) {
            throw new \RuntimeException(
                "No implementation found for game type '{$gameType->slug}'. " .
                "Please register it in GameTypeRegistry::\$implementations."
            );
        }

        // Instantiate and cache
        $instance = new $implementationClass($gameType->config);

        if (!$instance instanceof GameTypeInterface) {
            throw new \RuntimeException(
                "Game type implementation '{$implementationClass}' must implement GameTypeInterface"
            );
        }

        $this->instances[$gameType->id] = $instance;

        return $instance;
    }

    /**
     * Get all active game types
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllActive()
    {
        return GameType::active()->get();
    }

    /**
     * Check if a game type is supported
     *
     * @param string $slug
     * @return bool
     */
    public function isSupported(string $slug): bool
    {
        return isset($this->implementations[$slug]) &&
               GameType::where('slug', $slug)->where('is_active', true)->exists();
    }

    /**
     * Register a new game type implementation
     *
     * Useful for testing or dynamic registration
     *
     * @param string $slug
     * @param string $implementationClass
     * @return void
     */
    public function register(string $slug, string $implementationClass): void
    {
        if (!is_subclass_of($implementationClass, GameTypeInterface::class)) {
            throw new \InvalidArgumentException(
                "Implementation class must implement GameTypeInterface"
            );
        }

        $this->implementations[$slug] = $implementationClass;
    }

    /**
     * Clear the instance cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->instances = [];
    }

    /**
     * Get the default game type (Roll Up)
     *
     * @return GameTypeInterface
     */
    public function getDefault(): GameTypeInterface
    {
        return $this->getBySlug('roll-up');
    }
}
