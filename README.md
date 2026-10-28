# Snuggy Parking

Share where the household car is parked. Sign in with Google, belong to one or more households, tap "Park here" to record the spot (GPS position, optional photo and note), and see the last spot on a map with directions from any household member's phone. It is an installable PWA (Laravel 13, Inertia 3, Vue 3) that keeps working on a weak signal: pages you have seen and the last spot stay viewable offline.

## Requirements

Docker Desktop only. No host PHP or Node. On Windows use Git Bash or WSL for the commands below.

## First-time setup

```sh
# 1. Get vendor/ (the Sail image build needs it) without host PHP
docker run --rm -v "$(pwd)":/var/www/html -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs

# 2. Environment and containers
cp .env.example .env
docker compose up -d --build

# 3. Inside the app container
docker compose exec -u sail laravel.test composer install
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test php artisan key:generate
docker compose exec -u sail laravel.test php artisan migrate
```

Git Bash rewrites absolute paths passed to `docker compose exec`; prefix those commands with `MSYS_NO_PATHCONV=1`. `node_modules` is a named volume; after `docker compose down -v` it may need `docker compose exec laravel.test chown sail:sail /var/www/html/node_modules`.

## Google OAuth client

Google is the only sign-in method.

1. Google Cloud Console, APIs & Services, Credentials, Create credentials, OAuth client ID, type Web application.
2. Authorized redirect URIs: `http://localhost/auth/google/callback`, plus `https://<your-domain>/auth/google/callback` for the tunnel and for production.
3. Put the values in `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`.

Changes to redirect URIs can take a few minutes to propagate; `redirect_uri_mismatch` in the meantime is expected.

## Daily use

```sh
docker compose exec -u sail laravel.test npm run dev        # Vite dev server
docker compose exec -u sail laravel.test composer test      # pint --test, phpstan level 7, Pest
docker compose exec -u sail laravel.test npm run check      # format + lint
docker compose exec -u sail laravel.test npm run types:check
docker compose exec -u sail laravel.test npm run test:js    # frontend unit tests
```

The app is at http://localhost. Outgoing mail lands in Mailpit at http://localhost:8025. After adding routes, regenerate the typed route helpers with `php artisan wayfinder:generate --with-form` (without `--with-form` the `.form()` variants disappear).

The pgsql lock-order tests are a separate group:

```sh
docker compose exec -u sail laravel.test sh -c 'DB_CONNECTION=pgsql DB_HOST=pgsql DB_DATABASE=testing php artisan test --group=pgsql'
```

## Production build and the PWA

The service worker exists only in a production build. Stop `npm run dev` first (it leaves a `public/hot` file), then:

```sh
docker compose exec -u sail laravel.test npm run build
```

Installability can be checked in Chrome DevTools, Application, Manifest, "Installability".

Page history is encrypted in the browser, which needs a secure context (`crypto.subtle`): `http://localhost` and HTTPS work, a plain-http LAN IP does not.

## Real-device testing through the tunnel

Geolocation, install and the service worker need HTTPS, so test on a phone through ngrok.

1. Create an ngrok account and copy the authtoken.
2. A free account has exactly one assigned dev domain (dashboard, Domains). `NGROK_DOMAIN` must match it exactly or ngrok fails with `ERR_NGROK_313`.
3. In `.env`: `NGROK_AUTHTOKEN`, `NGROK_DOMAIN`, `APP_URL=https://<NGROK_DOMAIN>`, `TRUSTED_PROXIES=*`.
4. Add `https://<NGROK_DOMAIN>/auth/google/callback` to the Google client.
5. `docker compose --profile tunnel up -d`, then open `https://<NGROK_DOMAIN>` on the phone. The free tier shows an interstitial "Visit Site" page first.
6. Install: iPhone, Safari, Share, Add to Home Screen. Android, Chrome, menu, Install app.

## Production

The notes are stack-neutral; Docker is for development only.

- **PHP 8.4** with the usual Laravel extensions plus `pdo_sqlite` and/or `pdo_pgsql`, behind any web server (nginx, Apache) that serves `public/` over HTTPS. HTTPS is required for geolocation and the service worker.
- **Deploy steps:** `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, then `php artisan config:cache route:cache view:cache` (and `event:cache`). Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL` and `APP_KEY`.
- **Permissions:** the web user needs write access to `storage/` and `bootstrap/cache/`. Spot photos are on the private `local` disk (`storage/app/private/spots/`), never web-served; keep it out of the document root and include it in backups.
- **Uploads:** photos are limited to 5 MB. Set nginx `client_max_body_size` to at least 6M and PHP `upload_max_filesize` / `post_max_size` to at least 6M.
- **Proxy:** if a reverse proxy or load balancer terminates TLS, set `TRUSTED_PROXIES` to its address (or `*` only when the app is reachable solely through it).
- **Google:** add `https://<your-domain>/auth/google/callback` to the OAuth client and set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.
- **Temporary sign-in allowlist:** only the emails in `GOOGLE_ALLOWED_EMAILS` (comma-separated) can sign in, and invites to other emails are refused. `*` allows anyone. **Unset or empty lets nobody sign in**, so set it on every environment, including local `.env`. To remove the guard for good, delete `app/Support/SignInAllowlist.php` and its callers in `GoogleAuthController::callback()` and `StoreHouseholdInviteRequest`, the `google.allowed_emails` config key, `tests/Feature/Auth/GoogleAllowlistTest.php` and the `GOOGLE_ALLOWED_EMAILS` line in `phpunit.xml`.
- **Mail:** invite emails are sent synchronously with a 5 s `MAIL_TIMEOUT`; set the real `MAIL_*` values (the defaults point at Mailpit).
- **Queue and scheduler:** the app queues no jobs and schedules no tasks, so no queue worker or cron entry is needed. `QUEUE_CONNECTION`, `SESSION_DRIVER` and `CACHE_STORE` default to `database`.

### Database

Both drivers are supported; choose with `DB_CONNECTION`.

**SQLite**

- `DB_CONNECTION=sqlite`, `DB_DATABASE=<absolute path to the file>`.
- The web user needs write access to the file and its directory (WAL creates `-wal` and `-shm` side files next to it).
- Defaults in `config/database.php`, overridable via `DB_BUSY_TIMEOUT` (5000), `DB_JOURNAL_MODE` (wal), `DB_SYNCHRONOUS` (normal), `DB_TRANSACTION_MODE` (IMMEDIATE). `lockForUpdate()` is a no-op on SQLite; IMMEDIATE takes the write lock at `BEGIN`, which keeps the household-locked sections serial.
- Back up with `sqlite3 <db> ".backup '<dest>'"`, not by copying the file while the app runs.

**PostgreSQL (e.g. Supabase free tier)**

- Use the Session pooler on port 5432. The direct connection is IPv6-only on the free tier, and the Transaction pooler (6543) breaks PDO prepared statements.
- `DB_USERNAME=postgres.<project-ref>`, `DB_SSLMODE=require`.
- Free projects pause after about 7 idle days.

## Known limitations

- The iOS installed-app Google sign-in (the cookie risk, R8) was verified on a real iPhone through the tunnel and works.
- The stored email is the verified Google email as of the user's last login; a changed Google email is picked up at the next login (R13).
- No offline map: OpenStreetMap tile policy forbids bulk offline caching, so only tiles already viewed are kept briefly.
- No data deletion yet (no account, household or spot deletion UI).
