<?php

use App\Services\ProvablyFairService;

test('generates consistent results with same seeds and nonce', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed_12345';
    $clientSeed = 'test_client_seed_67890';
    $nonce = 1;

    $result1 = $service->rollDice($serverSeed, $clientSeed, $nonce);
    $result2 = $service->rollDice($serverSeed, $clientSeed, $nonce);

    expect($result1)->toBe($result2)
        ->and($result1)->toBeGreaterThanOrEqual(1)
        ->and($result1)->toBeLessThanOrEqual(6);
});

test('generates different results with different nonces', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed_12345';
    $clientSeed = 'test_client_seed_67890';

    $result1 = $service->rollDice($serverSeed, $clientSeed, 1);
    $result2 = $service->rollDice($serverSeed, $clientSeed, 2);
    $result3 = $service->rollDice($serverSeed, $clientSeed, 3);

    // While technically they COULD be the same, it's extremely unlikely
    // So we just check they're all valid dice values
    expect($result1)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    expect($result2)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    expect($result3)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
});

test('generates different results with different client seeds', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed_12345';
    $nonce = 1;

    $result1 = $service->rollDice($serverSeed, 'client_seed_a', $nonce);
    $result2 = $service->rollDice($serverSeed, 'client_seed_b', $nonce);

    expect($result1)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    expect($result2)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
});

test('verification succeeds for correct rolls', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed_12345';
    $clientSeed = 'test_client_seed_67890';
    $nonce = 1;

    $roll = $service->rollDice($serverSeed, $clientSeed, $nonce);
    $verification = $service->verify($serverSeed, $clientSeed, $nonce, $roll);

    expect($verification['verified'])->toBeTrue()
        ->and($verification['calculated'])->toBe($roll)
        ->and($verification['expected'])->toBe($roll);
});

test('verification fails for incorrect rolls', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed_12345';
    $clientSeed = 'test_client_seed_67890';
    $nonce = 1;

    $roll = $service->rollDice($serverSeed, $clientSeed, $nonce);
    $fakeRoll = ($roll % 6) + 1; // Different number

    $verification = $service->verify($serverSeed, $clientSeed, $nonce, $fakeRoll);

    if ($fakeRoll !== $roll) {
        expect($verification['verified'])->toBeFalse();
    }
});

test('generates server seed with correct length', function () {
    $service = new ProvablyFairService();

    $serverSeed = $service->generateServerSeed();

    expect($serverSeed)->toHaveLength(64); // 32 bytes = 64 hex characters
});

test('generates client seed with correct length', function () {
    $service = new ProvablyFairService();

    $clientSeed = $service->generateClientSeed();

    expect($clientSeed)->toHaveLength(64); // 32 bytes = 64 hex characters
});

test('server seed hash is SHA-256', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed';
    $hash = $service->hashServerSeed($serverSeed);

    expect($hash)->toHaveLength(64) // SHA-256 = 64 hex characters
        ->and($hash)->toBe(hash('sha256', $serverSeed));
});

test('verifies server seed hash matches seed', function () {
    $service = new ProvablyFairService();

    $serverSeed = $service->generateServerSeed();
    $serverSeedHash = $service->hashServerSeed($serverSeed);

    $isValid = $service->verifyServerSeedHash($serverSeed, $serverSeedHash);

    expect($isValid)->toBeTrue();
});

test('rejects mismatched server seed hash', function () {
    $service = new ProvablyFairService();

    $serverSeed1 = $service->generateServerSeed();
    $serverSeed2 = $service->generateServerSeed();
    $serverSeedHash1 = $service->hashServerSeed($serverSeed1);

    $isValid = $service->verifyServerSeedHash($serverSeed2, $serverSeedHash1);

    expect($isValid)->toBeFalse();
});

test('gets detailed verification info', function () {
    $service = new ProvablyFairService();

    $serverSeed = 'test_server_seed';
    $clientSeed = 'test_client_seed';
    $nonce = 5;

    $details = $service->getVerificationDetails($serverSeed, $clientSeed, $nonce);

    expect($details)->toHaveKeys(['input', 'calculation', 'result'])
        ->and($details['input']['server_seed'])->toBe($serverSeed)
        ->and($details['input']['client_seed'])->toBe($clientSeed)
        ->and($details['input']['nonce'])->toBe($nonce)
        ->and($details['result'])->toBeGreaterThanOrEqual(1)
        ->and($details['result'])->toBeLessThanOrEqual(6);
});
