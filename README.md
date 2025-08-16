# Chess Multiplayer Backend (Laravel)

This project is a PHP Laravel backend for a modern chess multiplayer website. It provides a robust API for user authentication, matchmaking, game management, leaderboards, and more. The backend is designed for extensibility, security, and real-time play.

## Features

- **User Registration & Login**: Secure endpoints for user sign-up and authentication.
- **Game Management**: Create, sync, move, resign, offer/accept draws, and handle time controls for chess games.
- **Matchmaking Queue**: Join and leave matchmaking queues for different time controls.
- **Player Ratings & Leaderboards**: Track player ratings by time class and display leaderboards.
- **User Profile & Activity**: Fetch current user info, active games, and recent games.
- **API-First**: All endpoints are exposed via RESTful APIs.
- **Laravel Foundation**: Built on Laravel 12.x for reliability, security, and scalability.

## Main Controllers

- `AuthController`: Handles user registration and login.
- `GameController`: Manages all chess game actions (create, sync, move, resign, draw offers, timeouts, Elo calculation, etc.).
- `QueueController`: Handles matchmaking queue logic for pairing players.
- `MeController`: Provides endpoints for current user info, active game, recent games, and ratings.
- `LeaderboardController`: Returns leaderboards for a given time class.
- `ModeController`: Lists available time controls (modes).
- `FormController`: Example form handler for custom endpoints.

## Getting Started

### Prerequisites
- PHP 8.2+
- Composer
- SQLite/MySQL/PostgreSQL (default: SQLite)

### Installation
1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Copy `.env.example` to `.env` and update database settings
4. Generate application key:
   ```bash
   php artisan key:generate
   ```
5. Run migrations:
   ```bash
   php artisan migrate
   ```
6. (Optional) Seed the database:
   ```bash
   php artisan db:seed
   ```
7. Start the server:
   ```bash
   php artisan serve
   ```

## API Endpoints (Highlights)

- `POST /api/register` — Register a new user
- `POST /api/login` — Login and receive a token
- `POST /api/queue/join` — Join the matchmaking queue
- `POST /api/queue/leave` — Leave the matchmaking queue
- `GET /api/game/{id}` — Get game state
- `POST /api/game/{id}/move` — Make a move
- `POST /api/game/{id}/resign` — Resign from a game
- `POST /api/game/{id}/offer-draw` — Offer a draw
- `POST /api/game/{id}/accept-draw` — Accept a draw offer
- `GET /api/leaderboard` — Get leaderboard for a time class
- `GET /api/me` — Get current user info
- `GET /api/me/active-game` — Get current user's active game
- `GET /api/me/recent-games` — Get recent games for the user

> **Note:** See `routes/api.php` and controller files for the full set of endpoints and parameters.

## Project Structure

- `app/Http/Controllers/` — API controllers (see above)
- `app/Models/` — Eloquent models (e.g., `User`, `Game`, `GameMove`, etc.)
- `routes/api.php` — API route definitions
- `database/migrations/` — Database schema

## Extending for Chess

- Add new endpoints in `routes/api.php` and implement logic in the appropriate controller.
- Use Eloquent models for new data types (e.g., tournaments, chat).
- See `GameController` and `QueueController` for examples of chess-specific logic.

## License

MIT
