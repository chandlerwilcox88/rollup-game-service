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
        'status',
        'server_seed',
        'server_seed_hash',
        'total_rounds',
        'current_round',
        'turn_time_limit',
        'settings',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_rounds' => 'integer',
        'current_round' => 'integer',
        'turn_time_limit' => 'integer',
    ];

    protected $hidden = [
        'server_seed', // Hide until game is completed
    ];

    /**
     * Get the players for this game
     */
    public function players()
    {
        return $this->hasMany(GamePlayer::class, 'game_id');
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
