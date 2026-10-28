# 04 — Outline: Snuggy Parking PWA

Reads: `01-questions.md`, `03-design.md` (revision 5, approved 2026-10-27).

## Coverage

- **Id universe:** AC1–AC22 and FM1–FM29.
- **Withdrawn in design rev 4**, together with the handoff, and kept unclaimed on purpose: AC19, FM19, FM20, FM21, FM22.
- **Active ids: 46** (21 AC + 25 FM). **46 claimed, 0 unclaimed.**

## Phases

### P1 — Scaffold, Docker, test harness

- **Goal:** a pinned, stripped starter kit running in Sail, with a green Pest suite. Fortify is still present at the end of this phase; P2 replaces it, so `route('login')` exists throughout.
- **Scope:**
  - Create the kit from `c290133` with `--no-scripts`. Add `.env` and the app key, and merge `.gitignore`.
  - Strip the kit via chisel (`--answers`, `LARAVEL_INSTALLER_NO_NODE=1`).
  - Run `sail:install --with=pgsql,mailpit --php=8.4`. Then set:
    - `PHP_CLI_SERVER_WORKERS=4`;
    - a `node_modules` named volume;
    - `.gitattributes`;
    - Vite `server` host settings.
  - Run `npm install` and `composer install` inside the container.
  - Install Pest 4 and convert the remaining kit tests to it. Add `withoutVite()` to `TestCase`.
  - Disable SSR. Move the CI workflow to PHP 8.4.
- **Key files:** `composer.json`, `package.json`, `compose.yaml`, `.env.example`, `.gitattributes`, `.gitignore`, `vite.config.ts`, `config/inertia.php`, `tests/Pest.php`, `tests/TestCase.php`, `.github/workflows/tests.yml`.
- **Depends on:** nothing.
- **Model: opus.** Host and container bootstrap on Windows/Docker Desktop is unfamiliar ground. Chisel and Vite+ behaviour must be checked live, not assumed.
- **Satisfies:** AC15 (baseline: `composer test` and the frontend checks are green in the container).

### P2 — Google-only auth, households core

- **Goal:** Fortify is gone and Socialite is the only login. First login creates the user and their personal household. The current-household accessor exists.
- **Scope:**
  - **Removing Fortify:** the package, its provider, config and actions, the password pages and settings, `User` interfaces and traits, `Layout.vue`, `Profile.vue`, and the dashboard and welcome pages.
  - **Migrations:**
    - edit the users migration (`google_id`, `avatar`; drop `password` and verification columns);
    - add `households`, with `users.current_household_id` and a rollback order that drops the foreign key first;
    - add `household_user`.
  - **Models** `Household`, plus `User` relations and `currentHousehold()` (persisted, locked self-heal, memoised).
  - **Actions:** `CreatePersonalHousehold`, with a `UserFactory` `afterCreating` hook.
  - **Google flow:** `GoogleAuthController` with the callback rules below, plus the `login` route and page and `logout`. Callback rules:
    - require `email_verified`;
    - look up by `google_id`, falling back to email only where `google_id` is null;
    - refresh email and avatar;
    - refuse a mismatched `google_id`;
    - retry once outside the transaction;
    - `prompt=select_account`.
  - **Config and limits:**
    - a named `google-oauth` limiter;
    - session lifetime default 43200;
    - shared props: an explicit `auth.user`, plus lazy `currentHousehold` and `households`;
    - `preventLazyLoading` outside production.
  - **Profile:** name only.
  - **Pest tests** for every rule above.
- **Key files:**
  - `app/Http/Controllers/Auth/GoogleAuthController.php`
  - `app/Models/{User,Household}.php`
  - `app/Actions/CreatePersonalHousehold.php`
  - `database/migrations/*`
  - `routes/{web,auth,settings}.php`
  - `bootstrap/app.php`
  - `config/{services,session}.php`
  - `app/Http/Middleware/HandleInertiaRequests.php`
  - `resources/js/pages/auth/Login.vue`
  - `tests/Feature/Auth/*`
- **Depends on:** P1.
- **Model: opus.** This is an authentication boundary with account-takeover rules (I8) and a race (FM17), plus a self-healing invariant (I7, I3).
- **Satisfies:** AC1, AC2, AC14, AC17, AC18, FM4, FM5, FM16, FM17, FM29 (account chooser).

### P3 — Tunnel, minimal installable PWA, iPhone checkpoint

- **Goal:** the app is reachable over HTTPS and installable. Real-device Google sign-in can be checked **before** the domain work.
- **Scope:**
  - **ngrok service** under the `tunnel` profile.
  - **`config/trustedproxy.php`.**
  - **vite-plugin-pwa** in `injectManifest` mode with a minimal `resources/js/sw.ts`: precache plus the `SKIP_WAITING` handler. This proves R12 early. If `injectManifest` fails under Vite+, fall back to `generateSW` per design §8.
  - **PWA routes and assets:**
    - `/sw.js`, outside the `web` group, with an injectable path;
    - the manifest, icons and blade meta tags.
  - **Service-worker registration in `app.ts`:**
    - manual registration via `workbox-window` in production;
    - unregister in dev;
    - the update toast.
  - **README:** tunnel and OAuth setup steps.
  - **Pest tests:** `/sw.js` 200 and 404, and the trusted-proxy config.
- **Key files:** `compose.yaml`, `config/trustedproxy.php`, `vite.config.ts`, `resources/js/sw.ts`, `resources/js/app.ts`, `bootstrap/app.php`, `routes/pwa.php`, `config/pwa.php`, `public/icons/*`, `resources/views/app.blade.php`, `README.md`.
- **Depends on:** P2, because there must be a login to test.
- **Model: opus.** Service-worker scope and registration, plus an unproven build mode (R12), and proxy trust timing (F40).
- **Satisfies:** AC21 (manual), AC13 (partial: manifest and SW registration; completed in P6).
- **Acceptance:** the AC21 device check is presented to the user **before P4 starts** (P4 is marked `gate-before` in the plan). If iOS sign-in fails in the installed app, the user decides whether to open the follow-up handoff task first or to continue.

### P4 — Households, invites, membership

- **Goal:** the household page, the switcher, invites (create, mail, accept, cancel), and member removal and leave, all under policies and the global lock order.
- **Scope:**
  - **Data:** `household_invites` migration and model, with a `pending()` scope.
  - **Authorization:** `HouseholdPolicy`.
  - **Controllers:**
    - `HouseholdController` (show the current household);
    - `CurrentHouseholdController` (PUT);
    - `HouseholdInviteController` (store, destroy);
    - `InviteAcceptanceController` (show, accept);
    - `HouseholdMemberController` (destroy, leave).
  - **Mail:** `HouseholdInviteMail`, with the timeout set in `config/mail.php`.
  - **Rate limiting:** the `invites` limiter.
  - **Pages:** `Household.vue`, `Invites/Show.vue`, and the header switcher.
  - **Pest tests** for authorization, plus the FM paths: mail failure, double accept, cancel during accept, removal of the current household, and an existing member.
- **Key files:**
  - `app/Http/Controllers/Household*`, `app/Http/Controllers/InviteAcceptanceController.php`
  - `app/Policies/HouseholdPolicy.php`
  - `app/Models/HouseholdInvite.php`, `app/Mail/HouseholdInviteMail.php`
  - `config/mail.php`, `routes/web.php`
  - `resources/js/pages/{Household,Invites/Show}.vue`, `resources/js/components/HouseholdSwitcher.vue`
  - `tests/Feature/Households/*`
- **Depends on:** P2, and the P3 checkpoint.
- **Model: opus.** It has a lock-ordering invariant (KD11, FM28), the single-use guarantee in I5, and cross-household pointer updates (FM8).
- **Satisfies:** AC8 (switching to a non-member household is refused), AC9, AC10, AC11, AC20, FM6, FM7, FM8, FM15, FM23, FM28.

### P5 — Parking spots, map, directions

- **Goal:** park, retrieve, photo and history, with the Leaflet map and directions. This is the core user flow.
- **Scope:**
  - **Data:** the `parking_spots` migration (partial unique index, casts) and the `ParkingSpot` model.
  - **Authorization:** `ParkingSpotPolicy` (`denyAsNotFound`) and `HouseholdPolicy::parkIn`.
  - **`ParkingSpotController`** (store / show / retrieve / photo):
    - the submitted `household_id` is checked under the household lock;
    - the photo is stored first, a `false` result throws, and the photo is deleted if the save fails;
    - `retrieve` is idempotent.
  - **Storage:** the local disk gets `serve => false`.
  - **Server-side `directions_url`.**
  - **Pages:**
    - `Home.vue`: the geolocation button, confirm sheet with accuracy, note and canvas-downscaled photo, error states for codes 1–3, the active card, and the last 10 spots with eager loading;
    - `Spots/Show.vue`: Leaflet with CORS tiles, attribution, marker icon fix and accuracy circle.
  - **Pest tests:** authorization for every spot route, the one-active-spot index, the photo failure paths, and the exact directions URL.
- **Key files:**
  - `app/Models/ParkingSpot.php`, `app/Policies/ParkingSpotPolicy.php`
  - `app/Http/Controllers/ParkingSpotController.php`, `app/Http/Requests/StoreParkingSpotRequest.php`
  - `config/filesystems.php`, `database/migrations/*_create_parking_spots_table.php`
  - `resources/js/pages/{Home,Spots/Show}.vue`
  - `resources/js/components/{ParkSheet,SpotMap}.vue`
  - `resources/js/composables/{useGeolocation,usePhotoDownscale}.ts`
  - `tests/Feature/Spots/*`
- **Depends on:** P2, and P4 for `parkIn` and membership fixtures. P5 could run in parallel with P4 as far as files go, but it needs the P4 policy, so it is sequenced after.
- **Model: opus.** It has concurrency (FM1, FM18), a file-and-database ordering problem (FM2, FM3), and a DB-enforced invariant (I2).
- **Satisfies:** AC3, AC4, AC5, AC6, AC7, AC8 (cannot park in a non-member household), AC12, AC16, FM1, FM2, FM3, FM10, FM11, FM12, FM14, FM18.

### P6 — Offline behaviour and user-scoped device data

- **Goal:** complete the SW and the weak-signal UX: the user-scoped data rules (I11), the saved-copy banner, the device-local last spot, the pending-spot safety net, and the offline page.
- **Scope:**
  - **`sw.ts` routing:**
    - never intercept `/auth`, `/login`, `/logout`, `/invites`, `/sw.js` or any non-GET;
    - NetworkFirst for navigations, Inertia GETs (excluding partial reloads) and photos;
    - CacheFirst for assets and OSM tiles;
    - a redirect-rejecting `cacheWillUpdate`;
    - `/offline` precached with the build-timestamp revision, and used as the navigation fallback.
  - **The `/offline` route and Blade view**, with `offline.ts` as a Vite input.
  - **Composables:**
    - `useLastSpot`: per-user key, `snuggy:last-user` pointer, saved / unsaved / retrieved statuses;
    - `useDeviceData`: clears on logout, on a user change, and on the login page while keeping the pointer user's unsaved entry.
  - **History:** `history.encrypt` is on and logout calls `clearHistory`. Logout is a full form POST.
  - **The saved-copy banner**, with `generated_at` shared via `Inertia::always()`.
  - **The park sheet's waiting states**, including the 20 s timeout and the network-error handler, plus the Home prompt to "Save now / Discard" an unsaved spot.
  - **Pest tests:** `/offline` without a session, logout history clearing, and `generated_at` present on partial reloads.
  - **Manual checks** for AC22 and FM24–FM27.
- **Key files:**
  - `resources/js/sw.ts`, `resources/js/offline.ts`
  - `resources/views/offline.blade.php`, `routes/pwa.php`, `config/inertia.php`
  - `resources/js/composables/{useLastSpot,useDeviceData}.ts`
  - `resources/js/components/{SavedCopyBanner,UnsavedSpotPrompt}.vue`
  - `resources/js/pages/{Home,Spots/Show,auth/Login}.vue`
  - `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`
- **Depends on:** P3 (SW skeleton) and P5 (spot pages).
- **Model: opus.** It spans the browser, SW and server, with ordering-sensitive state (clearing vs. pointer vs. unsaved entry) and an invariant on user-scoped data (I11).
- **Satisfies:** AC13 (complete), AC22, FM9, FM13, FM24, FM25, FM26, FM27, FM29 (device data cleared on user change).

### P7 — Documentation and end-to-end verification

- **Goal:** the README is complete, PostgreSQL migrations are proven, and every gate is green in the container.
- **Scope:**
  - **README sections:** bootstrap, Google OAuth client with both redirect URIs, `docker compose exec` usage, dev server, tunnel, production notes (upload limits, `TRUSTED_PROXIES`), and the known limits (R8, R13).
  - **PostgreSQL migrations:** `migrate:fresh --seed` and `migrate:rollback` against pgsql in Docker.
  - **Full checks:** `composer test` (pint, phpstan level 7, Pest) and `npm run check` / `types:check`.
  - **Install check:** a production build, then Lighthouse installability on `localhost`.
- **Key files:** `README.md`, `database/seeders/DatabaseSeeder.php`.
- **Depends on:** P1–P6.
- **Model: sonnet.** It is mechanical: documentation and running commands. Every file is named.
- **Satisfies:** AC15 (final).

## Id → phase map

| Id | Phase(s) | | Id | Phase(s) |
|---|---|---|---|---|
| AC1 | P2 | | FM1 | P5 |
| AC2 | P2 | | FM2 | P5 |
| AC3 | P5 | | FM3 | P5 |
| AC4 | P5 | | FM4 | P2 |
| AC5 | P5 | | FM5 | P2 |
| AC6 | P5 | | FM6 | P4 |
| AC7 | P5 | | FM7 | P4 |
| AC8 | P4, P5 | | FM8 | P4 |
| AC9 | P4 | | FM9 | P6 |
| AC10 | P4 | | FM10 | P5 |
| AC11 | P4 | | FM11 | P5 |
| AC12 | P5 | | FM12 | P5 |
| AC13 | P3, P6 | | FM13 | P6 |
| AC14 | P2 | | FM14 | P5 |
| AC15 | P1, P7 | | FM15 | P4 |
| AC16 | P5 | | FM16 | P2 |
| AC17 | P2 | | FM17 | P2 |
| AC18 | P2 | | FM18 | P5 |
| AC19 | withdrawn | | FM19–FM22 | withdrawn |
| AC20 | P4 | | FM23 | P4 |
| AC21 | P3 | | FM24–FM27 | P6 |
| AC22 | P6 | | FM28 | P4 |
| | | | FM29 | P2, P6 |
