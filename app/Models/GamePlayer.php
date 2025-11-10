<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GamePlayer extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'username',
        'position',
        'client_seed',
        'total_score',
        'placement',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'position' => 'integer',
        'total_score' => 'integer',
        'placement' => 'integer',
        'joined_at' => 'datetime',
    ];

    /**
     * Get the game this player belongs to
     */
    public function game()
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * Get all rolls for this player
     */
    public function rolls()
    {
        return $this->hasMany(PlayerRoll::class, 'game_player_id');
    }

    /**
     * Check if player has rolled in a specific round
     */
    public function hasRolledInRound(int $roundNumber): bool
    {
        return $this->rolls()
            ->where('round_number', $roundNumber)
            ->exists();
    }

    /**
     * Get player's roll for a specific round
     */
    public function getRollForRound(int $roundNumber)
    {
        return $this->rolls()
            ->where('round_number', $roundNumber)
            ->first();
    }
}
