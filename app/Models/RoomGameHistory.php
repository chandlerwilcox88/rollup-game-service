<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Room Game History Model
 *
 * Tracks all completed games within a room for match history.
 */
class RoomGameHistory extends Model
{
    protected $table = 'room_game_history';

    protected $fillable = [
        'room_code',
        'game_id',
        'game_number',
        'winner_user_id',
        'winner_username',
        'total_rounds',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the game associated with this history entry
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * Get the latest game number for a room
     */
    public static function getNextGameNumber(string $roomCode): int
    {
        return self::where('room_code', $roomCode)->max('game_number') + 1;
    }

    /**
     * Get all games for a room, ordered by game number
     */
    public static function getRoomHistory(string $roomCode): array
    {
        return self::where('room_code', $roomCode)
            ->orderBy('game_number', 'desc')
            ->get()
            ->toArray();
    }
}
