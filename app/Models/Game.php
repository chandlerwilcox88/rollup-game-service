<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Game extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'room_code',
        'game_type_id',
        'status',
        'server_seed',
        'server_seed_hash',
        'total_rounds',
        'current_round',
        'current_player_id',
        'turn_state',
        'turn_time_limit',
        'settings',
        'game_config',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'game_config' => 'array',
        'turn_state' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_rounds' => 'integer',
        'current_round' => 'integer',
        'current_player_id' => 'integer',
        'turn_time_limit' => 'integer',
        'game_type_id' => 'integer',
    ];

    protected $hidden = [
        'server_seed', // Hide until game is completed
    ];

    /**
     * Get the game type for this game
     */
    public function gameType()
    {
        return $this->belongsTo(GameType::class, 'game_type_id');
    }

    /**
     * Get the players for this game
     */
    public function players()
    {
        return $this->hasMany(GamePlayer::class, 'game_id');
    }

    /**
     * Get the current player (for turn-based games like Roll Up)
     */
    public function currentPlayer()
    {
        return $this->belongsTo(GamePlayer::class, 'current_player_id');
    }

    /**
     * Get the rounds for this game
     */
    public function rounds()
    {
        return $this->hasMany(GameRound::class, 'game_id');
    }

    /**
     * Get all rolls for this game
     */
    public function rolls()
    {
        return $this->hasMany(PlayerRoll::class, 'game_id');
    }

    /**
     * Check if game is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if game is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Check if game is waiting to start
     */
    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    /**
     * Get the winner (player with highest score)
     */
    public function getWinner()
    {
        return $this->players()
            ->orderBy('total_score', 'desc')
            ->first();
    }
}
