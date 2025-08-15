# Chess Multiplayer Backend (Laravel)

This is a PHP Laravel backend for a Chess multiplayer website. It provides user authentication, form handling, and a foundation for building real-time multiplayer chess games.

## Features

- **User Registration & Login**: Secure endpoints for user sign-up and authentication.
- **API-First**: All endpoints are exposed via RESTful APIs (see `routes/api.php`).
- **Extensible Controllers**: Easily add new endpoints for chess games, moves, matchmaking, and more.
- **Laravel Foundation**: Built on Laravel 12.x for reliability, security, and scalability.

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

## API Endpoints

- `POST /api/register` — Register a new user
- `POST /api/login` — Login and receive a token
- `POST /api/form-endpoint` — Example form handler

> **Note:** Endpoints for chess games, moves, and matchmaking are ready to be added in `routes/api.php` and corresponding controllers.

## Project Structure

- `app/Http/Controllers/` — API controllers (e.g., `AuthController`, `FormController`)
- `app/Models/` — Eloquent models (e.g., `User`)
- `routes/api.php` — API route definitions
- `database/migrations/` — Database schema

## Extending for Chess

To add chess-specific features:
- Create models (e.g., `Game`, `Move`)
- Add controllers for game logic and matchmaking
- Define new API routes in `routes/api.php`
- Use Laravel events/broadcasting for real-time play

## Testing
Run tests with:
```bash
php artisan test
```

## License
MIT

---
Built with Laravel for modern multiplayer chess experiences.
