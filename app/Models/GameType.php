<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GameType Model
 *
 * Represents a type of dice game (Roll Up, Yahtzee, Liar's Dice, etc.)
 * Stores game configuration in a flexible JSONB format.
 */
class GameType extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_active',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Games that use this game type
     */
    public function games()
    {
        return $this->hasMany(Game::class);
    }

    /**
     * Get a config value with dot notation support
     *
     * @param string $key e.g., 'dice.count', 'scoring.bonuses'
     * @param mixed $default
     * @return mixed
     */
    public function getConfigValue(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Get dice configuration
     */
    public function getDiceConfig(): array
    {
        return $this->getConfigValue('dice', [
            'count' => 2,
            'min' => 1,
            'max' => 6,
        ]);
    }

    /**
     * Get scoring configuration
     */
    public function getScoringConfig(): array
    {
        return $this->getConfigValue('scoring', []);
    }

    /**
     * Get player limits
     */
    public function getPlayerLimits(): array
    {
        return [
            'min' => $this->getConfigValue('min_players', 2),
            'max' => $this->getConfigValue('max_players', 6),
        ];
    }

    /**
     * Get allowed actions
     */
    public function getAllowedActions(): array
    {
        return $this->getConfigValue('actions', ['roll']);
    }

    /**
     * Scope to only active game types
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
