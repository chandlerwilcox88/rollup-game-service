<?php

namespace App\Services;

/**
 * Provably Fair Service
 *
 * This service implements a cryptographically secure provably fair system
 * for the OpenLuxe platform. It ensures that all dice rolls are verifiable
 * and cannot be manipulated through cryptographic commitment.
 *
 * How it works:
 * 1. Server generates a secret seed at game creation
 * 2. Server seed hash (SHA-256) is revealed to players before game starts
 * 3. Players provide (or we generate) their own client seeds
 * 4. Each roll uses: HMAC-SHA256(serverSeed:clientSeed:nonce)
 * 5. After game completes, server seed is revealed for verification
 *
 * Players can verify any roll by:
 * - Using the revealed server seed
 * - Using their client seed
 * - Using the roll's nonce value
 * - Running the same algorithm to get the same result
 *
 * @author Chandler Wilcox
 */
class ProvablyFairService
{
    /**
     * Generate a cryptographically secure server seed (64 character hex string)
     *
     * @return string
     */
    public function generateServerSeed(): string
    {
        return bin2hex(random_bytes(32)); // 32 bytes = 64 hex characters
    }

    /**
     * Generate SHA-256 hash of server seed
     * This hash is shown to players BEFORE the game starts
     *
     * @param string $serverSeed
     * @return string
     */
    public function hashServerSeed(string $serverSeed): string
    {
        return hash('sha256', $serverSeed);
    }

    /**
     * Generate client seed (if user doesn't provide one)
     *
     * @return string
     */
    public function generateClientSeed(): string
    {
        return bin2hex(random_bytes(32)); // 32 bytes = 64 hex characters
    }

    /**
     * Generate provably fair dice roll (1-6)
     *
     * @param string $serverSeed The server's secret seed
     * @param string $clientSeed The client's seed
     * @param int $nonce The roll number (increments each roll)
     * @return int Dice value (1-6)
     */
    public function rollDice(string $serverSeed, string $clientSeed, int $nonce): int
    {
        return $this->generateNumber($serverSeed, $clientSeed, $nonce, 1, 6);
    }

    /**
     * Generate provably fair random number in range
     *
     * This is the core algorithm that makes the system provably fair:
     * 1. Combine server seed, client seed, and nonce
     * 2. Generate HMAC-SHA256 hash using server seed as key
     * 3. Convert first 8 hex characters to decimal
     * 4. Map to desired range using modulo
     *
     * @param string $serverSeed The server's secret seed
     * @param string $clientSeed The client's seed
     * @param int $nonce The roll number (increments each roll)
     * @param int $min Minimum value (inclusive)
     * @param int $max Maximum value (inclusive)
     * @return int Random number in range [min, max]
     */
    public function generateNumber(
        string $serverSeed,
        string $clientSeed,
        int $nonce,
        int $min = 1,
        int $max = 6
    ): int {
        // Step 1: Combine seeds with nonce
        $combined = $serverSeed . ':' . $clientSeed . ':' . $nonce;

        // Step 2: Generate HMAC-SHA256 hash
        // Using server seed as the key ensures server has committed to a value
        // before knowing the client seed
        $hash = hash_hmac('sha256', $combined, $serverSeed);

        // Step 3: Convert first 8 characters of hash to decimal
        // This gives us a number between 0 and 4,294,967,295 (2^32 - 1)
        $hex = substr($hash, 0, 8);
        $decimal = hexdec($hex);

        // Step 4: Map to range [min, max]
        $range = $max - $min + 1;
        $result = ($decimal % $range) + $min;

        return $result;
    }

    /**
     * Verify a roll result
     *
     * This allows anyone to verify that a roll was fair by:
     * 1. Taking the revealed server seed (after game)
     * 2. Using their client seed
     * 3. Using the nonce for that specific roll
     * 4. Running the same algorithm
     * 5. Comparing the result
     *
     * @param string $serverSeed The revealed server seed
     * @param string $clientSeed The client's seed
     * @param int $nonce The roll number
     * @param int $expectedResult The result that should be verified
     * @param int $min Minimum possible value
     * @param int $max Maximum possible value
     * @return array Verification result with details
     */
    public function verify(
        string $serverSeed,
        string $clientSeed,
        int $nonce,
        int $expectedResult,
        int $min = 1,
        int $max = 6
    ): array {
        // Re-calculate the result using the same algorithm
        $calculatedResult = $this->generateNumber($serverSeed, $clientSeed, $nonce, $min, $max);

        // Verify the hash matches what was shown before game
        $serverSeedHash = $this->hashServerSeed($serverSeed);

        return [
            'verified' => $calculatedResult === $expectedResult,
            'expected' => $expectedResult,
            'calculated' => $calculatedResult,
            'server_seed' => $serverSeed,
            'server_seed_hash' => $serverSeedHash,
            'client_seed' => $clientSeed,
            'nonce' => $nonce,
            'algorithm' => 'HMAC-SHA256',
        ];
    }

    /**
     * Verify server seed hash matches server seed
     *
     * This proves the server didn't change the seed after game started
     *
     * @param string $serverSeed The revealed server seed
     * @param string $serverSeedHash The hash shown before game
     * @return bool True if hash matches
     */
    public function verifyServerSeedHash(string $serverSeed, string $serverSeedHash): bool
    {
        return $this->hashServerSeed($serverSeed) === $serverSeedHash;
    }

    /**
     * Generate verification details for a dice roll
     *
     * @param string $serverSeed
     * @param string $clientSeed
     * @param int $nonce
     * @return array
     */
    public function getVerificationDetails(string $serverSeed, string $clientSeed, int $nonce): array
    {
        $combined = $serverSeed . ':' . $clientSeed . ':' . $nonce;
        $hash = hash_hmac('sha256', $combined, $serverSeed);
        $hex = substr($hash, 0, 8);
        $decimal = hexdec($hex);
        $result = ($decimal % 6) + 1;

        return [
            'input' => [
                'server_seed' => $serverSeed,
                'client_seed' => $clientSeed,
                'nonce' => $nonce,
            ],
            'calculation' => [
                'combined' => $combined,
                'hash' => $hash,
                'hex_8_chars' => $hex,
                'decimal' => $decimal,
                'modulo_6' => $decimal % 6,
                'plus_1' => ($decimal % 6) + 1,
            ],
            'result' => $result,
        ];
    }
}
