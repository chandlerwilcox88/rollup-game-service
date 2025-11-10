<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameRound extends Model
{
    protected $fillable = [
        'game_id',
        'round_number',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'round_number' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the game this round belongs to
     */
    public function game()
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * Check if round is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if round is in rolling state
     */
    public function isRolling(): bool
    {
        return $this->status === 'rolling';
    }

    /**
     * Check if round is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
