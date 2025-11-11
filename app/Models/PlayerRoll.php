<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerRoll extends Model
{
    protected $fillable = [
        'game_id',
        'game_player_id',
        'round_number',
        'nonce',
        'dice1_value',
        'dice2_value',
        'dice_values',
        'roll_total',
        'bonus_points',
        'total_points',
        'rolled_at',
    ];

    protected $casts = [
        'game_player_id' => 'integer',
        'round_number' => 'integer',
        'nonce' => 'integer',
        'dice1_value' => 'integer',
        'dice2_value' => 'integer',
        'dice_values' => 'array',
        'roll_total' => 'integer',
        'bonus_points' => 'integer',
        'total_points' => 'integer',
        'rolled_at' => 'datetime',
    ];

    /**
     * Get the game this roll belongs to
     */
    public function game()
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * Get the player who made this roll
     */
    public function player()
    {
        return $this->belongsTo(GamePlayer::class, 'game_player_id');
    }

    /**
     * Get dice values as array
     * Backward compatible: uses dice_values if available, otherwise falls back to dice1/dice2
     */
    public function getDiceAttribute(): array
    {
        // Use new dice_values format if available
        if ($this->dice_values !== null && !empty($this->dice_values)) {
            return $this->dice_values;
        }

        // Fallback to old format for backward compatibility
        return [$this->dice1_value, $this->dice2_value];
    }

    /**
     * Check if roll was doubles
     */
    public function isDoubles(): bool
    {
        return $this->dice1_value === $this->dice2_value;
    }

    /**
     * Check if roll was a seven
     */
    public function isSeven(): bool
    {
        return $this->roll_total === 7;
    }

    /**
     * Check if roll was snake eyes (1+1)
     */
    public function isSnakeEyes(): bool
    {
        return $this->dice1_value === 1 && $this->dice2_value === 1;
    }

    /**
     * Check if roll was boxcars (6+6)
     */
    public function isBoxcars(): bool
    {
        return $this->dice1_value === 6 && $this->dice2_value === 6;
    }
}
