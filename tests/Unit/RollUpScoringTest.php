<?php

use App\GameTypes\RollUpGameType;

function rules(): RollUpGameType
{
    return new RollUpGameType(['dice' => ['count' => 7, 'min' => 1, 'max' => 6]]);
}

test('a hold scores the best split of the selected dice', function (array $dice, int $points) {
    expect(rules()->scoreSelection($dice))->toBe($points);
})->with([
    'single 1' => [[1], 100],
    'single 5' => [[5], 50],
    'two 1s and a 5' => [[1, 1, 5], 250],
    'three 1s' => [[1, 1, 1], 1000],
    'three 4s' => [[4, 4, 4], 400],
    'three 2s plus a 5' => [[2, 2, 2, 5], 250],
    'four 1s (three 1s + one 1 beats four of a kind)' => [[1, 1, 1, 1], 1100],
    'four 3s' => [[3, 3, 3, 3], 600],
    'five 5s and a 1' => [[5, 5, 5, 5, 5, 1], 2600],
    'six 6s' => [[6, 6, 6, 6, 6, 6], 6000],
    'small straight' => [[1, 2, 3, 4, 5], 500],
    'large straight' => [[6, 5, 4, 3, 2, 1], 1500],
    'small straight plus 5' => [[1, 2, 3, 4, 5, 5], 550],
    'full house' => [[2, 2, 2, 3, 3], 300],
    'two triples' => [[2, 2, 2, 3, 3, 3], 500],
]);

test('a hold containing a dead die is illegal', function (array $dice) {
    expect(rules()->scoreSelection($dice))->toBeNull();
})->with([
    'lone 2' => [[2]],
    'pair of 3s' => [[3, 3]],
    '1 plus a 6' => [[1, 6]],
    'three 4s plus a 2' => [[4, 4, 4, 2]],
    'nothing' => [[]],
]);

test('a throw with no scoring die is a bust', function () {
    expect(rules()->hasScoringDice([2, 3, 4, 6, 6, 2]))->toBeFalse()
        ->and(rules()->hasScoringDice([2, 3, 4, 6, 6, 1]))->toBeTrue()
        ->and(rules()->hasScoringDice([2, 2, 2]))->toBeTrue()
        ->and(rules()->hasScoringDice([6]))->toBeFalse();
});

test('best hold reports die positions, not compacted indices', function () {
    // positions 1 and 4 were held earlier; the thrown dice keep their positions
    $best = rules()->bestHold([0 => 2, 2 => 1, 3 => 6, 5 => 5]);

    expect($best['indices'])->toBe([2, 5])
        ->and($best['points'])->toBe(150);
});

test('evaluateThrow maps combination indices back to positions', function () {
    $eval = rules()->evaluateThrow([0 => 3, 2 => 3, 4 => 3]);

    expect($eval['is_bust'])->toBeFalse()
        ->and($eval['best_combination']['name'])->toBe('three_of_a_kind')
        ->and($eval['best_combination']['dice_indices'])->toBe([0, 2, 4]);

    expect(rules()->evaluateThrow([1 => 2, 3 => 6])['is_bust'])->toBeTrue();
});
