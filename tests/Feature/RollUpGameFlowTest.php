<?php

/*
 * Plays real Roll Up games through the HTTP API, start to finish.
 *
 * The test keeps its OWN ledger of what every total should be (bank on + adds
 * the pot once, bank on - subtracts it from the chosen rival, a bust loses the
 * pot, a roll changes nothing) and checks the server against it after every
 * action. After each game it reveals the server seed and re-derives every die
 * of every roll from (server_seed, client_seed, nonce).
 */

use App\GameTypes\RollUpGameType;
use App\Services\ProvablyFairService;
use Database\Seeders\UpdateRollUpGameTypeSeeder;

uses(Tests\TestCase::class);

const FIXED_SERVER_SEED = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';

beforeEach(function () {
    // fresh schema per test (TestCase refuses any database not named *_testing)
    $this->artisan('migrate:fresh');
    $this->artisan('db:seed', ['--class' => UpdateRollUpGameTypeSeeder::class]);
});

/** Call the API as OpenLuxe. Returns [status, decoded body]. */
function api($test, string $method, string $uri, array $data = []): array
{
    $test->json($method, $uri, $data, [
        'X-API-Key' => 'test-api-key',
        'Origin' => 'https://openluxe.test',
    ]);

    return [$test->lastResponse()->getStatusCode(), json_decode($test->lastResponse()->getContent(), true)];
}

function act($test, string $gameId, int $userId, string $action, array $data = []): array
{
    return api($test, 'POST', "/api/games/{$gameId}/action", [
        'user_id' => $userId,
        'action' => $action,
        'data' => $data,
    ]);
}

function ok($test, string $gameId, int $userId, string $action, array $data = []): array
{
    [$status, $body] = act($test, $gameId, $userId, $action, $data);
    expect($status)->toBe(200, "{$action} by {$userId} failed: " . json_encode($body));

    return $body['data'];
}

function createGame($test, array $players, int $rounds): array
{
    [$status, $body] = api($test, 'POST', '/api/games', [
        'room_code' => 'TST' . random_int(100, 999),
        'players' => $players,
        'settings' => ['rounds' => $rounds],
    ]);
    expect($status)->toBe(201, json_encode($body));

    return $body['data'];
}

function state($test, string $gameId): array
{
    [, $body] = api($test, 'GET', "/api/games/{$gameId}");

    return $body['data'];
}

function totals(array $state): array
{
    $totals = collect($state['players'])->mapWithKeys(fn ($p) => [$p['user_id'] => $p['total_score']])->all();
    ksort($totals);

    return $totals;
}

function useFixedServerSeed($test): void
{
    $test->bindInstance(ProvablyFairService::class, new class extends ProvablyFairService {
        public function generateServerSeed(): string
        {
            return FIXED_SERVER_SEED;
        }
    });
}

/** First client seed (by search) whose opening throw satisfies $predicate(regularDice, specialDie). */
function findClientSeed(callable $predicate, string $prefix): string
{
    $pf = new ProvablyFairService();
    for ($i = 0; $i < 5000; $i++) {
        $seed = "{$prefix}-{$i}";
        $dice = array_map(fn ($n) => $pf->generateNumber(FIXED_SERVER_SEED, $seed, $n, 1, 6), range(0, 6));
        if ($predicate(array_slice($dice, 0, 6), $dice[6])) {
            return $seed;
        }
    }
    throw new RuntimeException('no seed found');
}

/**
 * Play one player's whole turn with a strategy, checking every response.
 *
 * $style: 'safe'   - hold the best dice, bank as soon as the pot is >= 300
 *         'greedy' - keep rolling until bust (bank only after 25 throws)
 *         'once'   - bank right after the first hold
 *
 * @return array the turn's log (for the fairness check)
 */
function playTurn($test, string $gameId, int $userId, string $style, array &$ledger, array $userIds, array &$log, array &$stats): void
{
    $pot = 0;
    $special = null;
    $prevDice = null;
    $heldBefore = [];
    $throws = 0;

    while (true) {
        $before = totals(state($test, $gameId));
        $r = ok($test, $gameId, $userId, 'roll');
        $throws++;

        // the exact dice, in die order, for the client to animate onto
        expect($r['dice'])->toHaveCount(7)
            ->and($r['held'])->toHaveCount(7)
            ->and($r['nonces'])->toHaveCount(7);
        foreach ($r['dice'] as $v) {
            expect($v)->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
        }

        // the special die is thrown once per turn and keeps its face
        if ($special === null) {
            expect($r['rolled_indices'])->toContain(6);
            $special = $r['dice'][6];
        } else {
            expect($r['rolled_indices'])->not->toContain(6)
                ->and($r['dice'][6])->toBe($special);
        }
        expect($r['special_die_positive'])->toBe($special <= 4);

        // held dice keep their face and are not re-thrown
        foreach ($r['held_indices'] as $i) {
            expect($r['rolled_indices'])->not->toContain($i)
                ->and($r['dice'][$i])->toBe($prevDice[$i])
                ->and($r['held'][$i])->toBeTrue();
        }
        foreach ($r['rolled_indices'] as $i) {
            expect($r['held'][$i])->toBeFalse();
        }

        // a roll never changes anybody's total
        expect(totals(state($test, $gameId)))->toBe($before)
            ->and($r['player_total_score'])->toBe($ledger[$userId]);

        $log[] = ['user_id' => $userId, 'round' => $r['round'], 'roll' => $r];
        $prevDice = $r['dice'];

        if ($r['is_bust']) {
            $stats['busts']++;
            expect($r['pot_lost'])->toBe($pot)
                ->and($r['turn_state']['phase'])->toBe('bust')
                ->and($r['turn_state']['pending_score'])->toBe(0)
                ->and($r['best_hold'])->toBeNull();
            if ($pot > 0) {
                $stats['busts_with_pot']++;
            }

            // the busted player cannot act again this round
            if (!$r['round_advanced']) {
                [$status, $body] = act($test, $gameId, $userId, 'roll');
                expect($status)->toBe(409)->and($body['code'])->toBe('turn_completed');
            }

            return;
        }

        // must hold before rolling again
        [$status, $body] = act($test, $gameId, $userId, 'roll');
        expect($status)->toBe(409)->and($body['code'])->toBe('must_hold_first');

        $h = ok($test, $gameId, $userId, 'hold', ['held_dice_indices' => $r['best_hold']['indices']]);
        expect($h['score_added'])->toBe($r['best_hold']['points']);
        $pot += $h['score_added'];
        expect($h['pending_score'])->toBe($pot);
        $stats['holds']++;
        if ($h['hot_dice']) {
            $stats['hot_dice']++;
        }

        $bank = match ($style) {
            'once' => true,
            'safe' => $pot >= 300,
            'greedy' => $throws >= 25,
        };

        if (!$bank) {
            continue;
        }

        $positive = $special <= 4;
        $target = null;
        if (!$positive) {
            // a minus die sends the pot to a rival: pick the leader among the others
            $rivals = array_values(array_filter($userIds, fn ($id) => $id !== $userId));
            usort($rivals, fn ($a, $b) => $ledger[$b] <=> $ledger[$a]);
            $target = $rivals[0];
        }

        $b = ok($test, $gameId, $userId, 'bank', $target ? ['target_player_id' => $target] : []);
        expect($b['banked_score'])->toBe($pot);

        if ($positive) {
            $ledger[$userId] += $pot;
            $stats['banks_positive']++;
        } else {
            $ledger[$target] = max(0, $ledger[$target] - $pot);
            $stats['banks_negative']++;
            expect($b['target_player']['user_id'])->toBe($target)
                ->and($b['target_player']['new_total_score'])->toBe($ledger[$target]);
        }
        expect($b['player_total_score'])->toBe($ledger[$userId]);

        return;
    }
}

/** Re-derive every die of every logged roll from the revealed server seed. */
function assertRollsAreProvablyFair($test, string $gameId, array $log, array $clientSeeds, string $publishedHash): void
{
    [, $seedBody] = api($test, 'GET', "/api/games/{$gameId}/server-seed");
    $serverSeed = $seedBody['data']['server_seed'];
    expect(hash('sha256', $serverSeed))->toBe($publishedHash);

    $pf = new ProvablyFairService();
    $seenNonces = [];

    foreach ($log as $entry) {
        $r = $entry['roll'];
        $uid = $entry['user_id'];
        expect($r['fairness']['server_seed_hash'])->toBe($publishedHash)
            ->and($r['fairness']['client_seed'])->toBe($clientSeeds[$uid]);

        foreach ($r['rolled_indices'] as $position) {
            $nonce = $r['nonces'][$position];
            expect($nonce)->toBe($r['nonce'] + $position);
            expect($seenNonces[$uid][$nonce] ?? false)->toBeFalse("nonce {$nonce} reused for {$uid}");
            $seenNonces[$uid][$nonce] = true;
            expect($pf->generateNumber($serverSeed, $clientSeeds[$uid], $nonce, 1, 6))->toBe($r['dice'][$position]);
        }
    }

    // and the service's own verifier agrees, for every player and round played
    $pairs = collect($log)->map(fn ($e) => [$e['user_id'], $e['round']])->unique(fn ($p) => implode(':', $p));
    foreach ($pairs as [$uid, $round]) {
        [$status, $body] = api($test, 'POST', "/api/games/{$gameId}/verify", ['user_id' => $uid, 'round_number' => $round]);
        expect($status)->toBe(200)
            ->and($body['data']['verified'])->toBeTrue()
            ->and($body['data']['server_seed_matches_hash'])->toBeTrue();
    }
}

function playFullGame($test, array $players, array $styles, int $rounds): array
{
    $clientSeeds = collect($players)->mapWithKeys(fn ($p) => [$p['id'] => $p['client_seed']])->all();
    $userIds = array_keys($clientSeeds);
    $game = createGame($test, $players, $rounds);
    $gameId = $game['game_id'];
    $hash = $game['server_seed_hash'];

    expect($game['status'])->toBe('in_progress')
        ->and($game['current_round'])->toBe(1)
        ->and($hash)->toHaveLength(64);

    // the seed stays secret until the game is over
    [$status] = api($test, 'GET', "/api/games/{$gameId}/server-seed");
    expect($status)->toBe(403);

    $ledger = array_fill_keys($userIds, 0);
    $log = [];
    $stats = ['busts' => 0, 'busts_with_pot' => 0, 'holds' => 0, 'hot_dice' => 0, 'banks_positive' => 0, 'banks_negative' => 0];

    for ($round = 1; $round <= $rounds; $round++) {
        $s = state($test, $gameId);
        expect($s['current_round'])->toBe($round)->and($s['status'])->toBe('in_progress');

        // every player starts the round with a clean turn
        foreach ($s['players'] as $p) {
            expect($p['has_completed_turn_this_round'])->toBeFalse()
                ->and($p['turn_state']['round'])->toBe($round)
                ->and($p['turn_state']['phase'])->toBe('roll')
                ->and($p['turn_state']['can_roll'])->toBeTrue()
                ->and($p['turn_state']['pending_score'])->toBe(0)
                ->and($p['turn_state']['held_indices'])->toBe([]);
        }

        foreach ($userIds as $n => $uid) {
            playTurn($test, $gameId, $uid, $styles[$n], $ledger, $userIds, $log, $stats);
            expect(totals(state($test, $gameId)))->toEqual($ledger);

            $s = state($test, $gameId);
            if ($n < count($userIds) - 1) {
                // the round waits for the rest of the table
                expect($s['current_round'])->toBe($round);
            }
        }
    }

    $s = state($test, $gameId);
    expect($s['status'])->toBe('completed')
        ->and($s['current_round'])->toBe($rounds)
        ->and(totals($s))->toEqual($ledger);

    // nobody can act on a finished game
    [$status, $body] = act($test, $gameId, $userIds[0], 'roll');
    expect($status)->toBe(409)->and($body['code'])->toBe('game_not_in_progress');

    [$status, $results] = api($test, 'GET', "/api/games/{$gameId}/results");
    expect($status)->toBe(200);
    $best = max($ledger);
    expect($results['data']['winner']['total_score'])->toBe($best)
        ->and($ledger[$results['data']['winner']['user_id']])->toBe($best);
    foreach ($results['data']['players'] as $p) {
        $expectedPlacement = 1 + count(array_filter($ledger, fn ($t) => $t > $p['total_score']));
        expect($p['placement'])->toBe($expectedPlacement)
            ->and($p['total_score'])->toBe($ledger[$p['user_id']]);
    }

    assertRollsAreProvablyFair($test, $gameId, $log, $clientSeeds, $hash);

    return ['game_id' => $gameId, 'ledger' => $ledger, 'stats' => $stats, 'log' => $log];
}

test('a two-player game plays start to finish with correct scoring', function () {
    $result = playFullGame($this, [
        ['id' => 101, 'username' => 'ana', 'position' => 1, 'client_seed' => 'ana-seed'],
        ['id' => 102, 'username' => 'ben', 'position' => 2, 'client_seed' => 'ben-seed'],
    ], ['safe', 'greedy'], 5);

    expect($result['stats']['busts'])->toBeGreaterThan(0)
        ->and($result['stats']['holds'])->toBeGreaterThan(0)
        ->and($result['stats']['banks_positive'] + $result['stats']['banks_negative'])->toBeGreaterThan(0);
});

test('a three-player game plays start to finish with busts, banks, holds and rollover', function () {
    $result = playFullGame($this, [
        ['id' => 201, 'username' => 'cy', 'position' => 1, 'client_seed' => 'cy-seed'],
        ['id' => 202, 'username' => 'di', 'position' => 2, 'client_seed' => 'di-seed'],
        ['id' => 203, 'username' => 'ed', 'position' => 3, 'client_seed' => 'ed-seed'],
    ], ['once', 'safe', 'greedy'], 6);

    expect($result['stats']['busts'])->toBeGreaterThan(0)
        ->and($result['stats']['holds'])->toBeGreaterThan(5);
});

test('many seeded games all finish with a consistent ledger', function () {
    useFixedServerSeed($this);
    $totals = ['busts' => 0, 'busts_with_pot' => 0, 'hot_dice' => 0, 'banks_negative' => 0, 'banks_positive' => 0];

    foreach (range(1, 6) as $g) {
        $result = playFullGame($this, [
            ['id' => 300 + $g, 'username' => "p{$g}a", 'position' => 1, 'client_seed' => "game{$g}-a"],
            ['id' => 400 + $g, 'username' => "p{$g}b", 'position' => 2, 'client_seed' => "game{$g}-b"],
            ['id' => 500 + $g, 'username' => "p{$g}c", 'position' => 3, 'client_seed' => "game{$g}-c"],
        ], ['safe', 'greedy', 'safe'], 4);

        foreach ($totals as $k => $_) {
            $totals[$k] += $result['stats'][$k];
        }
    }

    // across 72 turns every rule path is exercised
    expect($totals['busts'])->toBeGreaterThan(0)
        ->and($totals['busts_with_pot'])->toBeGreaterThan(0)
        ->and($totals['banks_positive'])->toBeGreaterThan(0)
        ->and($totals['banks_negative'])->toBeGreaterThan(0);
});

test('a bust on the opening throw ends the turn, records a bust and lets the round finish', function () {
    useFixedServerSeed($this);
    $rules = new RollUpGameType([]);
    $bustSeed = findClientSeed(fn ($regular) => !$rules->hasScoringDice($regular), 'bust');
    $plusSeed = findClientSeed(fn ($regular, $special) => $rules->hasScoringDice($regular) && $special <= 4, 'plus');

    $game = createGame($this, [
        ['id' => 1, 'username' => 'buster', 'position' => 1, 'client_seed' => $bustSeed],
        ['id' => 2, 'username' => 'banker', 'position' => 2, 'client_seed' => $plusSeed],
    ], 2);
    $id = $game['game_id'];

    $r = ok($this, $id, 1, 'roll');
    expect($r['is_bust'])->toBeTrue()
        ->and($r['best_combination']['name'])->toBe('none')
        ->and($r['turn_state']['phase'])->toBe('bust')
        ->and($r['round_advanced'])->toBeFalse();

    $this->seeInDatabase('player_rolls', ['round_number' => 1, 'action_type' => 'bust', 'is_bust' => true]);

    foreach (['roll', 'hold', 'bank'] as $action) {
        [$status, $body] = act($this, $id, 1, $action, ['held_dice_indices' => [0]]);
        expect($status)->toBe(409)->and($body['code'])->toBe('turn_completed');
    }

    $r = ok($this, $id, 2, 'roll');
    expect($r['is_bust'])->toBeFalse();
    ok($this, $id, 2, 'hold', ['held_dice_indices' => $r['best_hold']['indices']]);
    $b = ok($this, $id, 2, 'bank');
    expect($b['banked_score'])->toBe($r['best_hold']['points'])
        ->and($b['player_total_score'])->toBe($r['best_hold']['points'])
        ->and($b['round_advanced'])->toBeTrue()
        ->and($b['current_round'])->toBe(2)
        // the banker's turn state is already fresh for round 2
        ->and($b['turn_state']['phase'])->toBe('roll')
        ->and($b['turn_state']['round'])->toBe(2);

    $s = state($this, $id);
    expect($s['current_round'])->toBe(2);
    foreach ($s['players'] as $p) {
        expect($p['turn_state']['can_roll'])->toBeTrue()
            ->and($p['turn_state']['is_bust'])->toBeFalse()
            ->and($p['turn_state']['is_banked'])->toBeFalse()
            ->and($p['has_completed_turn_this_round'])->toBeFalse();
    }
    expect(totals($s))->toBe([1 => 0, 2 => $r['best_hold']['points']]);

    // round 2: the buster can play again
    $r2 = ok($this, $id, 1, 'roll');
    expect($r2['round'])->toBe(2)->and($r2['rolled_indices'])->toBe([0, 1, 2, 3, 4, 5, 6]);
});

test('illegal actions are rejected with clear 4xx errors', function () {
    useFixedServerSeed($this);
    $rules = new RollUpGameType([]);
    // an opening throw that scores, has a dead die to try to hold, and a minus special die
    $minusSeed = findClientSeed(
        fn ($regular, $special) => $special >= 5
            && $rules->hasScoringDice($regular)
            && collect($regular)->contains(fn ($v) => $v !== 1 && $v !== 5 && count(array_keys($regular, $v)) < 3),
        'minus'
    );

    $game = createGame($this, [
        ['id' => 11, 'username' => 'mia', 'position' => 1, 'client_seed' => $minusSeed],
        ['id' => 12, 'username' => 'noa', 'position' => 2, 'client_seed' => 'noa-seed'],
    ], 1);
    $id = $game['game_id'];

    // unknown player / game
    [$status, $body] = act($this, $id, 999, 'roll');
    expect($status)->toBe(404)->and($body['code'])->toBe('player_not_in_game');
    [$status] = act($this, '00000000-0000-0000-0000-000000000000', 11, 'roll');
    expect($status)->toBe(404);
    [$status] = act($this, $id, 11, 'dance');
    expect($status)->toBe(422);

    // hold / bank before rolling
    [$status, $body] = act($this, $id, 11, 'hold', ['held_dice_indices' => [0]]);
    expect($status)->toBe(409)->and($body['code'])->toBe('cannot_hold');
    [$status, $body] = act($this, $id, 11, 'bank');
    expect($status)->toBe(409)->and($body['code'])->toBe('cannot_bank');

    $r = ok($this, $id, 11, 'roll');
    expect($r['is_bust'])->toBeFalse()->and($r['special_die_positive'])->toBeFalse();

    // roll again / bank without holding
    [$status, $body] = act($this, $id, 11, 'roll');
    expect($status)->toBe(409)->and($body['code'])->toBe('must_hold_first');
    [$status, $body] = act($this, $id, 11, 'bank');
    expect($status)->toBe(409)->and($body['code'])->toBe('cannot_bank');

    // bad selections
    $dead = collect(range(0, 5))->first(fn ($i) => !in_array($r['dice'][$i], [1, 5], true)
        && count(array_keys(array_slice($r['dice'], 0, 6), $r['dice'][$i])) < 3);
    foreach ([
        [[], 'empty_hold'],
        [[$dead], 'non_scoring_hold'],
        [[6], 'invalid_index'],          // the special die is never held
        [[9], 'invalid_index'],
        [[$r['best_hold']['indices'][0], $r['best_hold']['indices'][0]], 'invalid_index'],
    ] as [$indices, $code]) {
        [$status, $body] = act($this, $id, 11, 'hold', ['held_dice_indices' => $indices]);
        expect($status)->toBe(422, json_encode($body))->and($body['code'])->toBe($code);
    }

    $h = ok($this, $id, 11, 'hold', ['held_dice_indices' => $r['best_hold']['indices']]);

    // can't hold twice from one throw, nor hold an already-held die
    [$status, $body] = act($this, $id, 11, 'hold', ['held_dice_indices' => [$r['best_hold']['indices'][0]]]);
    expect($status)->toBe(409)->and($body['code'])->toBe('cannot_hold');

    // minus die: must name a rival, never yourself, never a stranger
    [$status, $body] = act($this, $id, 11, 'bank');
    expect($status)->toBe(422)->and($body['code'])->toBe('target_required');
    [$status, $body] = act($this, $id, 11, 'bank', ['target_player_id' => 11]);
    expect($status)->toBe(422)->and($body['code'])->toBe('target_self');
    [$status, $body] = act($this, $id, 11, 'bank', ['target_player_id' => 777]);
    expect($status)->toBe(422)->and($body['code'])->toBe('target_not_in_game');

    $b = ok($this, $id, 11, 'bank', ['target_player_id' => 12]);
    expect($b['banked_score'])->toBe($h['pending_score'])
        ->and($b['player_total_score'])->toBe(0)
        ->and($b['target_player']['new_total_score'])->toBe(0); // never below zero

    // after banking the turn is over
    [$status, $body] = act($this, $id, 11, 'roll');
    expect($status)->toBe(409)->and($body['code'])->toBe('turn_completed');
    [$status, $body] = act($this, $id, 11, 'bank', ['target_player_id' => 12]);
    expect($status)->toBe(409)->and($body['code'])->toBe('turn_completed');

    // results and verification wait for the end of the game
    [$status] = api($this, 'GET', "/api/games/{$id}/results");
    expect($status)->toBe(409);
    [$status] = api($this, 'POST', "/api/games/{$id}/verify", ['user_id' => 11, 'round_number' => 1]);
    expect($status)->toBe(409);
});

test('hot dice: holding all six dice lets the player throw all six again and keep the pot', function () {
    useFixedServerSeed($this);
    $rules = new RollUpGameType([]);
    // an opening throw where every one of the six dice scores
    $hotSeed = findClientSeed(fn ($regular) => $rules->scoreSelection($regular) !== null, 'hot');

    $game = createGame($this, [
        ['id' => 21, 'username' => 'hot', 'position' => 1, 'client_seed' => $hotSeed],
        ['id' => 22, 'username' => 'cold', 'position' => 2, 'client_seed' => 'cold-seed'],
    ], 1);
    $id = $game['game_id'];

    $r = ok($this, $id, 21, 'roll');
    $h = ok($this, $id, 21, 'hold', ['held_dice_indices' => [0, 1, 2, 3, 4, 5]]);
    expect($h['hot_dice'])->toBeTrue()->and($h['score_added'])->toBe($rules->scoreSelection(array_slice($r['dice'], 0, 6)));

    $r2 = ok($this, $id, 21, 'roll');
    expect($r2['rolled_indices'])->toBe([0, 1, 2, 3, 4, 5])
        ->and($r2['held_indices'])->toBe([])
        ->and($r2['dice'][6])->toBe($r['dice'][6]);

    if (!$r2['is_bust']) {
        expect($r2['pending_score'])->toBe($h['pending_score']);
    } else {
        expect($r2['pot_lost'])->toBe($h['pending_score']);
    }
});
