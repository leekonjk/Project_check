# Project_check

Standalone PHP 8.4 application with Nginx, MySQL, an admin dashboard, and a JSON API for Unity.

## Local test

Run these commands from the Server folder with Docker Compose available.

```powershell
Copy-Item .env.example .env
```

Edit both MySQL passwords in `.env` before the first start. Use different random values. The database volume preserves data between runs. MySQL applies `schema.sql` on the first start with an empty volume.

```powershell
docker compose up -d --build
$adminPassword = Read-Host 'New admin password (at least 12 characters)' -MaskInput
$adminPassword | docker compose exec -T php php /app/create-admin.php admin@example.com
Remove-Variable adminPassword
```

Open [the admin dashboard](http://localhost:8080/admin). Use Ad settings to change the test banner or turn it off. Confirm the response at [the local banner endpoint](http://localhost:8080/api/v1/ads/config?platform=android).

## HTTPS hosting

For EC2, run the same Compose stack and place an HTTPS reverse proxy on the host in front of `127.0.0.1:8080`. Set `APP_ORIGIN` to the public HTTPS origin so the app enables secure admin cookies and HSTS. Keep PHP-FPM and MySQL on the private Compose network. Allow inbound HTTPS, allow HTTP only if needed for redirect or certificate issuance, and restrict SSH. At the reverse proxy, enforce per-client login rate limits using the actual client IP. Behind a host proxy the bundled Nginx limits apply to the proxy address as an aggregate limit unless real-IP forwarding is configured with a trusted proxy.

For Unity, set `AdConfig.BannerConfigUrl` to the hosted HTTPS endpoint ending in `/api/v1/ads/config?platform=android`. The legacy `config.php` URL also works. Test the Android banner with Google's test unit first. The local HTTP dashboard works for browser testing, while the Android banner fetch requires HTTPS.

This setup is for staging. Before a public launch, put the admin routes behind MFA-backed access, add email verification and password recovery for players, and set up database backups and restore testing.

## Player API

Send JSON with `Content-Type: application/json`. Protected routes use `Authorization: Bearer <access_token>`. Keep refresh tokens in secure device storage. The client must serialize token refreshes and save both returned tokens because each successful refresh rotates the pair.

| Method and route | Request or behavior |
| --- | --- |
| GET `/api/v1/ads/config?platform=android` | Public versioned banner configuration |
| POST `/api/v1/auth/register` | `email`, `password` (12–128 bytes) |
| POST `/api/v1/auth/login` | `email`, `password` |
| POST `/api/v1/auth/refresh` | `refresh_token` |
| POST `/api/v1/auth/logout` | Revokes the current session |
| GET `/api/v1/me` | Current player |
| GET `/api/v1/tournaments` | Published tournaments |
| GET `/api/v1/tournaments/{id}` | Enrollment, bracket and next eligible match |
| POST `/api/v1/tournaments/{id}/join` | Enrolls the current player |
| POST `/api/v1/matches/{id}/start` | Returns a player-bound attempt valid for two hours |
| POST `/api/v1/matches/{id}/finish` | `attempt_id`, `request_id`, integer `score_one`, integer `score_two` |

Create a draft, open enrollment, and enroll two test players through the API. Generate the bracket in the admin dashboard. Start a match using either assigned player's token. Submit the scores in bracket order and repeat the same request to check idempotency. Approve the result from the dashboard with a review note. Fetch the tournament again to confirm the winner and audit entry. A draw can be rejected for a replay.

The server tournament is a single-elimination staging workflow for 2, 4, 8, 16, 32 or 64 entrants. Unity's existing AFCON group stage and offline cup still run locally. Only the Unity banner has been connected to this API. The backend checks who can submit a score and requires an admin to verify it before advancing the bracket.
