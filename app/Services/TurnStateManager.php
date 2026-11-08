<?php

namespace App\Services;

use App\GameTypes\RollUpGameType;
use App\Models\GamePlayer;

/**
 * TurnStateManager
 *
 * Owns the per-player turn state for Roll Up. Every player plays one turn per
 * round (all players play the round at the same time), so the state is reset
 * for every player whenever a round starts.
 *
 * Phases:
 *   roll   - may roll (start of the turn)
 *   hold   - just rolled scoring dice; must hold at least one before anything else
 *   decide - has held; may bank the pot, or roll the remaining dice
 *   bust   - rolled no scoring dice; the turn is over and the pot is lost
 *   banked - banked the pot; the turn is over
 *
 * All die indices are POSITIONS: 0-5 regular dice, 6 the special die.
 */
class TurnStateManager
{
    public function initialState(int $round): array
    {
        return [
            'round' => $round,
            'phase' => 'roll',
            'dice' => array_fill(0, RollUpGameType::TOTAL_DICE, null),
            'held_indices' => [],
            'held_dice' => [],
            'available_indices' => range(0, RollUpGameType::REGULAR_DICE - 1),
            'available_dice' => [],
            'last_rolled_indices' => [],
            'special_die' => null,
            'special_die_positive' => null,
            'pending_score' => 0,
            'can_roll' => true,
            'can_hold' => false,
            'can_bank' => false,
            'turn_roll_count' => 0,
            'is_bust' => false,
            'is_banked' => false,
            'hot_dice' => false,
        ];
    }

    /**
     * Give every player a fresh turn for the given round.
     *
     * @param  iterable<GamePlayer>  $players
     */
    public function resetForRound(iterable $players, int $round): void
    {
        foreach ($players as $player) {
            $player->update(['turn_state' => $this->initialState($round)]);
        }
    }

    /**
     * The player's state for this round. A missing state, or one left over
     * from an earlier round, is replaced by a fresh one.
     */
    public function stateFor(GamePlayer $player, int $round): array
    {
        $state = $player->turn_state;

        if (empty($state) || ($state['round'] ?? null) !== $round) {
            $state = $this->initialState($round);
            $player->update(['turn_state' => $state]);
        }

        return $state;
    }

    /**
     * Which positions the next roll throws. Hot dice (all six held) throws all
     * six again; the special die is thrown only on the first roll of the turn.
     *
     * @return int[]
     */
    public function positionsToRoll(array $state): array
    {
        $held = $state['held_indices'] ?? [];
        if (count($held) >= RollUpGameType::REGULAR_DICE) {
            $held = [];
        }

        $positions = array_values(array_diff(range(0, RollUpGameType::REGULAR_DICE - 1), $held));

        if (($state['turn_roll_count'] ?? 0) === 0) {
            $positions[] = RollUpGameType::SPECIAL_DIE_INDEX;
        }

        return $positions;
    }

    /**
     * @param  array<int,int|null>  $dice  all 7 positional values after the throw
     * @param  int[]  $rolledIndices  positions thrown by this roll
     */
    public function afterRoll(GamePlayer $player, array $state, array $dice, array $rolledIndices, bool $isBust, bool $specialPositive): array
    {
        $regularRolled = array_values(array_filter($rolledIndices, fn ($i) => $i < RollUpGameType::REGULAR_DICE));

        // A hot-dice re-roll throws all six, so nothing is held any more.
        $held = count($regularRolled) === RollUpGameType::REGULAR_DICE ? [] : ($state['held_indices'] ?? []);

        $state['dice'] = $dice;
        $state['held_indices'] = $held;
        $state['held_dice'] = $this->valuesAt($dice, $held);
        $state['available_indices'] = $regularRolled;
        $state['available_dice'] = $this->valuesAt($dice, $regularRolled);
        $state['last_rolled_indices'] = $rolledIndices;
        $state['special_die'] = $dice[RollUpGameType::SPECIAL_DIE_INDEX];
        $state['special_die_positive'] = $specialPositive;
        $state['turn_roll_count'] = ($state['turn_roll_count'] ?? 0) + 1;
        $state['hot_dice'] = false;

        if ($isBust) {
            $state['phase'] = 'bust';
            $state['is_bust'] = true;
            $state['pending_score'] = 0;
            $state['can_roll'] = false;
            $state['can_hold'] = false;
            $state['can_bank'] = false;
        } else {
            $state['phase'] = 'hold';
            $state['can_roll'] = false;
            $state['can_hold'] = true;
            $state['can_bank'] = false;
        }

        $player->update(['turn_state' => $state]);

        return $state;
    }

    /**
     * @param  int[]  $indices  positions being set aside (all from the last throw)
     */
    public function afterHold(GamePlayer $player, array $state, array $indices, int $points): array
    {
        $held = array_values(array_unique(array_merge($state['held_indices'] ?? [], $indices)));
        sort($held);
        $available = array_values(array_diff(range(0, RollUpGameType::REGULAR_DICE - 1), $held));

        $state['held_indices'] = $held;
        $state['held_dice'] = $this->valuesAt($state['dice'], $held);
        $state['available_indices'] = $available;
        $state['available_dice'] = $this->valuesAt($state['dice'], $available);
        $state['pending_score'] = ($state['pending_score'] ?? 0) + $points;
        $state['hot_dice'] = count($held) === RollUpGameType::REGULAR_DICE;
        $state['phase'] = 'decide';
        $state['can_hold'] = false;
        $state['can_bank'] = true;
        $state['can_roll'] = true; // the remaining dice, or all six again on hot dice

        $player->update(['turn_state' => $state]);

        return $state;
    }

    public function afterBank(GamePlayer $player, array $state): array
    {
        $state['phase'] = 'banked';
        $state['is_banked'] = true;
        $state['pending_score'] = 0;
        $state['can_roll'] = false;
        $state['can_hold'] = false;
        $state['can_bank'] = false;

        $player->update(['turn_state' => $state]);

        return $state;
    }

    public function isTurnComplete(array $state): bool
    {
        return ($state['is_banked'] ?? false) || ($state['is_bust'] ?? false);
    }

    public function clearTurnState(GamePlayer $player): void
    {
        $player->update(['turn_state' => null]);
    }

    public function getTurnState(GamePlayer $player): ?array
    {
        return $player->turn_state;
    }

    private function valuesAt(array $dice, array $positions): array
    {
        return array_values(array_map(fn ($i) => $dice[$i] ?? null, $positions));
    }
}
