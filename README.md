# Project_check

Standalone PHP application with MySQL, an admin dashboard, and a JSON API for Unity. Deploy directly to shared hosting or use the optional Nginx Docker stack.

## Shared hosting without Docker

Use a domain or subdomain with HTTPS, PHP 8.3 or newer with PDO MySQL and Argon2id, MySQL or MariaDB, and URL rewriting. Use the PHP version and database credentials provided by the host. The app expects to live at the root of its domain or subdomain.

1. Create a database and user in the hosting panel and grant that user access to the database. Import `schema.sql` into the empty database using phpMyAdmin or the host's database tool.
2. In your account home directory, create `football-app` next to `public_html`. Upload `app.php`, `api.php`, `admin.php`, `views.php`, `admob.php`, and `create-admin.php` to `football-app`.
3. Copy `config.shared.example.php` to `football-app/config.local.php`. Fill in the provider's database host, complete database name, username, password, and public HTTPS origin. Keep this file readable only by your account and its PHP process.
4. Upload the contents of `public/` to `public_html`, including the hidden `.htaccess` file. Preserve any PHP handler directives already in the host's `.htaccess` and add the app's rewrite rules before any existing catch-all rules. On an Nginx-only host, ask the provider to route requests other than `/style.css` to `index.php`, forward the Authorization header to PHP, and use this public directory as the document root.
5. Create the admin from the hosting terminal before sharing the site. Run the commands below from your account home directory.

```sh
read -rs -p 'New admin password (at least 12 characters): ' admin_password
printf '%s' "$admin_password" | php football-app/create-admin.php admin@example.com
unset admin_password
```

If the host has no terminal, generate an Argon2id password hash using PHP on your computer and insert the first user with that hash and `is_admin = 1` through phpMyAdmin. Use a fresh admin email and a unique password of 12–128 bytes. The browser has no admin setup page.

Sign in at `/admin`, then check `/api/v1/ads/config?platform=android` and use the HTTPS URL in Unity. Enable HTTPS redirects in the hosting panel and configure the host's firewall or proxy to rate-limit sign-in and registration. The bundled Nginx rate limits apply to the Docker setup.

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

For staging on a directly exposed EC2 Nginx server, set `ADMIN_ALLOWED_IPS` in `config.local.php` to a comma-separated list of trusted public IP addresses. An empty value leaves the existing password-protected admin reachable. The restriction checks the address received by the web server, so set up trusted real-IP handling before using it behind a proxy. The Unity API remains accessible.

## Connect AdMob reporting

The admin-only `/admin/reports` page queries AdMob Network for the selected 7 or 30 complete days in Pacific time. It shows estimated earnings and ad delivery by date, format, country, or ad unit. Reporting requires the PHP cURL extension. On EC2 with PHP 8.5, install it with `sudo apt install php8.5-curl` and reload `php8.5-fpm`. The feature uses the existing database schema.

1. In Google Cloud, enable the AdMob API and create OAuth client credentials for a Google account with access to the intended AdMob publisher. Request only the `https://www.googleapis.com/auth/admob.report` scope and obtain an offline refresh token for that client. See [Google's AdMob OAuth setup](https://developers.google.com/admob/api/v1/auth).
2. Store the values in the private `config.local.php` file or server environment. Set `ADMOB_PUBLISHER_ID` to the account's `pub-…` ID, `ADMOB_APP_ID` to the real Android app's `ca-app-pub-…~…` ID, and set `ADMOB_CLIENT_ID`, `ADMOB_CLIENT_SECRET`, and `ADMOB_REFRESH_TOKEN`. Use the same client that issued the refresh token. Keep the client secret and refresh token out of Git, Unity, and the public web folder.
3. Sign in to Football Admin and open **AdMob reports**. Generate a report and compare the figures with the same app, date range, and time zone in AdMob.

The app's Google sample ad units are for device testing. Use a real app ID for reporting. To enable live serving, configure your actual AdMob app ID in Unity, your real format-specific ad unit IDs, and the published privacy messages for that app. Player traffic, rewarded completion events, and placement diagnostics require a separate Unity telemetry integration.

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
