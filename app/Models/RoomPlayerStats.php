<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Room Player Stats Model
 *
 * Tracks player statistics across all games in a specific room.
 */
class RoomPlayerStats extends Model
{
    protected $table = 'room_player_stats';

    protected $fillable = [
        'room_code',
        'user_id',
        'username',
        'games_played',
        'games_won',
        'total_score',
        'average_score',
        'best_score',
        'first_place_finishes',
        'second_place_finishes',
        'third_place_finishes',
    ];

    protected $casts = [
        'average_score' => 'decimal:2',
    ];

    /**
     * Get stats for all players in a room, ordered by wins
     */
    public static function getRoomStats(string $roomCode): array
    {
        return self::where('room_code', $roomCode)
            ->orderByDesc('games_won')
            ->orderByDesc('first_place_finishes')
            ->get()
            ->toArray();
    }

    /**
     * Update or create player stats after a game
     */
    public static function updatePlayerStats(
        string $roomCode,
        int $userId,
        string $username,
        int $score,
        int $placement
    ): void {
        $stats = self::firstOrNew([
            'room_code' => $roomCode,
            'user_id' => $userId,
        ]);

        $stats->username = $username;
        $stats->games_played++;
        $stats->total_score += $score;
        $stats->average_score = $stats->total_score / $stats->games_played;

        if ($score > $stats->best_score) {
            $stats->best_score = $score;
        }

        if ($placement === 1) {
            $stats->games_won++;
            $stats->first_place_finishes++;
        } elseif ($placement === 2) {
            $stats->second_place_finishes++;
        } elseif ($placement === 3) {
            $stats->third_place_finishes++;
        }

        $stats->save();
    }
}
