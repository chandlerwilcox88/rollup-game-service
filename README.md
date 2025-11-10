# Roll Up - Provably Fair Multiplayer Dice Game Service

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3+-777BB4.svg)](https://www.php.net/)
[![Lumen](https://img.shields.io/badge/Lumen-10.x-E74430.svg)](https://lumen.laravel.com/)

**Roll Up** is an open-source, provably fair multiplayer dice game microservice built with Lumen. It implements a cryptographically secure random number generation system ensuring complete transparency and verifiability through cryptographic commitment.

## Why Open Source?

We believe **transparency is the foundation of trust** in gaming. By open-sourcing Roll Up, we allow anyone to:

- ✅ Audit the complete game logic
- ✅ Verify the provably fair algorithm
- ✅ Ensure no backdoors or cheating mechanisms exist
- ✅ Contribute improvements to the codebase
- ✅ Learn from a real-world implementation of provably fair gaming

---

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env with your PostgreSQL credentials

# 3. Create database
createdb rollup_game

# 4. Run migrations
php artisan migrate

# 5. Start server
php -S localhost:8000 -t public

# 6. Test API
curl http://localhost:8000/api/health
```

---

## Game Rules

### Overview
- **Players**: 2-6 per game
- **Rounds**: 10 rounds (configurable)
- **Turn Timer**: 15 seconds (configurable)

### Scoring
- **Base**: Sum of dice (1-6 each)
- **Seven** (total = 7): +3 bonus
- **Doubles** (same number): +5 bonus
- **Snake Eyes** (1+1): +10 bonus
- **Boxcars** (6+6): +15 bonus

**Example**: Rolling [6,6] = 12 + 5 (doubles) + 15 (boxcars) = **32 points!**

---

## How Provably Fair Works

### TL;DR
Every dice roll is verifiable using cryptography. The server cannot cheat, and you can prove it mathematically.

### The Algorithm

1. **Server commits** to a secret seed (shows hash to players BEFORE game)
2. **Player provides** their own seed
3. **Each roll** combines: `HMAC-SHA256(serverSeed:clientSeed:nonce)`
4. **After game**, server reveals seed → players verify all rolls

### Why It Works

- Server can't change seed (hash would be different)
- Server can't pre-calculate (doesn't know client seed)
- Anyone can verify (algorithm is public, code is open source)

**See full explanation**: [HOW_PROVABLY_FAIR_WORKS.md](docs/provably-fair.md)

---

## API Documentation

### Base URL
```
Development: http://localhost:8000
Production: https://rollup.laravel.cloud
```

### Key Endpoints

#### Create Game
```http
POST /api/games
{
  "room_code": "ABC123",
  "players": [
    {"id": 1, "username": "player1", "position": 1},
    {"id": 2, "username": "player2", "position": 2}
  ]
}
```

#### Roll Dice
```http
POST /api/games/{gameId}/action
{
  "user_id": 1,
  "action": "roll"
}
```

#### Verify Roll (after game)
```http
POST /api/games/{gameId}/verify
{
  "user_id": 1,
  "round_number": 3
}
```

**Full API Docs**: See [API.md](docs/API.md) or test with the examples in `tests/`

---

## Project Structure

```
app/
├── Http/Controllers/
│   └── GameController.php           # API endpoints
├── Models/
│   ├── Game.php                     # Game + provably fair seeds
│   ├── GamePlayer.php               # Players + scores
│   └── PlayerRoll.php               # Individual rolls
├── Services/
│   ├── ProvablyFairService.php      # ⭐ Cryptographic RNG
│   ├── ScoringService.php           # Bonus calculations
│   └── GameService.php              # Game orchestration

database/migrations/                  # PostgreSQL schema
tests/                                # Pest PHP tests
```

---

## Technology Stack

- **Framework**: Lumen 10 (Laravel's microservice framework)
- **Database**: PostgreSQL 14+
- **Cache/Queue**: Redis (optional)
- **Testing**: Pest PHP
- **Language**: PHP 8.3+

---

## Testing

```bash
# Run all tests
./vendor/bin/pest

# Run specific test
./vendor/bin/pest tests/Unit/ProvablyFairServiceTest.php

# With coverage
./vendor/bin/pest --coverage
```

### Key Tests
- ✅ Provably fair algorithm generates consistent results
- ✅ Verification succeeds for valid rolls
- ✅ Server seed hash matches revealed seed
- ✅ Scoring bonuses calculate correctly

---

## Deployment

### PostgreSQL Setup
You'll need to configure PostgreSQL credentials in your `.env` file and create the database:

```bash
# Create database
createdb rollup_game

# Or using psql
psql -U postgres -c "CREATE DATABASE rollup_game;"

# Run migrations
php artisan migrate
```

### Laravel Cloud (Recommended)
```bash
composer global require laravel/cloud-cli
laravel-cloud deploy
```

### Manual Deployment
1. Set up PostgreSQL + Redis
2. Configure `.env` with production values
3. Run `php artisan migrate --force`
4. Point web server to `public/` directory

---

## Environment Variables

```env
# Application
APP_NAME="Roll Up Game Service"
APP_ENV=production
APP_KEY=base64:...

# Database (PostgreSQL)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rollup_game
DB_USERNAME=postgres
DB_PASSWORD=

# Cache/Queue (Redis optional)
CACHE_DRIVER=file
QUEUE_CONNECTION=sync

# CORS (for OpenLuxe integration)
CORS_ALLOWED_ORIGINS=https://openluxe.com
```

---

## Integration with OpenLuxe

This microservice is designed to integrate with the OpenLuxe platform:

```php
// OpenLuxe calls this service via HTTP
$response = Http::post('https://rollup.laravel.cloud/api/games', [
    'room_code' => $room->code,
    'players' => $room->players,
]);
```

---

## Security

### Cryptographic Guarantees
- ✅ Server seed: 256-bit random (32 bytes)
- ✅ Hash algorithm: SHA-256
- ✅ RNG algorithm: HMAC-SHA256
- ✅ All rolls verifiable post-game

### Reporting Vulnerabilities
Please email security issues privately (do not open public issues).

---

## Contributing

We welcome contributions!

1. Fork the repo
2. Create feature branch: `git checkout -b feature/amazing`
3. Commit changes: `git commit -m 'Add amazing feature'`
4. Push: `git push origin feature/amazing`
5. Open Pull Request

---

## License

MIT License - see [LICENSE](LICENSE) for details.

---

## Acknowledgments

- Created by [Chandler Wilcox](https://github.com/chandlerwilcox)
- Built for [OpenLuxe](https://openluxe.com)
- Powered by [Lumen](https://lumen.laravel.com/) & [PostgreSQL](https://postgresql.org/)

---

**Made with ❤️ for transparent, fair gaming**
