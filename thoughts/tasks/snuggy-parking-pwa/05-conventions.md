# Conventions

## Phase P1
- `tests/Pest.php:10-12` (pattern) — `Feature` gets `TestCase` + `RefreshDatabase`, `Unit` `TestCase` only; plain `test('…')` files under `tests/Feature/<Area>/`, never per-file `uses(RefreshDatabase)`. `TestCase::setUp` calls `withoutVite()`.
- `phpunit.xml` (constant) — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `APP_URL=http://localhost`, `TRUSTED_PROXIES=""`; `sail:install` rewrites the DB keys, restore them if re-run. `.env.example` is the single source of env keys (`GOOGLE_*`, `TRUSTED_PROXIES`, `MAIL_TIMEOUT=5`, `NGROK_*`); mirror new keys into `.env`.
- Commands (naming rule) — `docker compose exec -u sail laravel.test <cmd>`, chains via `sh -c '…'`; Git Bash needs `MSYS_NO_PATHCONV=1`. `node_modules` is a named volume (chowned `sail`); container git needs `safe.directory /var/www/html` for `pint --dirty` (lost on recreate).
- `composer test` (pattern) — `config:clear`, `pint --test`, phpstan level 7 (app/, bootstrap/app.php, config/, database/, routes/; not tests), Pest. `vp fmt <files>` fixes what `vp check` reports; keep `fmt.ignorePatterns` `'thoughts/**'` in `vite.config.ts`.

## Phase P2
- `User::currentHousehold()` (helper) at `app/Models/User.php:65` — only reader of `current_household_id`; memoised per instance, self-heals and persists; `$user->fresh()` after membership changes. `CreatePersonalHousehold` (`app/Actions/CreatePersonalHousehold.php:10`) locks the user row, reuses an owned household, sets current.
- `User::isMemberOf()` / `Household::isOwnedBy()` (helper) at `User.php:90`, `Household.php` — the membership/ownership predicates (`isMemberOf` queries fresh every call); policies use these, never inline `household_user` queries.
- `UserFactory` (pattern) at `database/factories/UserFactory.php:41` — every factory user owns + belongs to "{First}'s household" (I7); `withoutPersonalHousehold()` opts out. `Household::factory()` attaches its owner. `fakeGoogleUser(array)` at `tests/Pest.php:20` fakes a verified Google account.
- Named limiters (naming rule) at `AppServiceProvider::configureRateLimiting()` — `RateLimiter::for('<name>', … ->by('<name>|'.<key>))`; `google-oauth`, `invites` exist.
- Shared props (pattern) at `HandleInertiaRequests.php:46` — explicit `only()`/maps (no `pivot`); `currentHousehold`/`households` are lazy closures.
- Flash (pattern) — `Inertia::flash('toast', ['type'=>…,'message'=>…])`; tests `assertInertiaFlash('toast.message', '…')`; a flash survives to the next request in the same test, so never assert one *missing* after a flashing request.
- CSRF/logout/layout (constant) — `<meta name="csrf-token">` at `app.blade.php:6`; `logout` CSRF-exempt at `bootstrap/app.php:26`; `AppLayout` → `AppHeaderLayout`, nav items in `AppHeader.vue` `mainNavItems`.

## Phase P3
- `routes/pwa.php` (pattern) at `bootstrap/app.php:18` — routes outside `web` (no session): `pwa.sw` (`config('pwa.sw_path')`), `pwa.offline`; tests `assertHeaderMissing('Set-Cookie')`. `config('trustedproxy.proxies')` = `env('TRUSTED_PROXIES')`, unset = none; `ngrok` service, profile `tunnel`.
- `resources/js/sw.ts` (pattern) — hand-written `injectManifest` worker; `beforeBuildServiceWorker` (`vite.config.ts`) rewrites the manifest entry, so extra precache entries must be root-absolute; `__OFFLINE_REV__` define in `vite.config.ts`, typed in `resources/js/sw-env.d.ts`. `registerServiceWorker` / `unregisterServiceWorkers` in `resources/js/pwa/register.ts`. Brand teal `#0f766e`; icons from `resources/icons/logo.svg` via `rsvg-convert`.

## Phase P4
- `HouseholdPolicy` (pattern) at `app/Policies/HouseholdPolicy.php:12` — auto-discovered, bools (403); `switchTo`/`parkIn` take `?Household`, authorized as `Gate::authorize('<ability>', [Household::class, $h])` so a null reaches the policy.
- Lock order (pattern) — household row first, then invite (`HouseholdInviteController.php:58`, `InviteAcceptanceController.php:51`); every mutation re-checks after `Household::whereKey($id)->lockForUpdate()`. `HouseholdMemberController::detach()` (:47): lock, re-check (404), detach, clear `current_household_id` only if it points here.
- `HouseholdInvite::scopePending` / `isPending()` (helper) at `app/Models/HouseholdInvite.php:63,68` — the one pending definition; `HouseholdInviteFactory` (`expired()`) defaults `invited_by` to the owner.
- `householdWithMember()` (helper) at `tests/Pest.php:42` — "Beach house" owned by "Alice Owner" + "Bob Member", current for both; returns `[$household, $owner, $member]`.
- `LogoutForm.vue` (pattern) — native POST + `_token` from the meta tag; the caller's slot supplies the `type="submit"` button; `@submit.prevent` forgets the user's device data, then `form.submit()` (P6).
- `tests/Feature/Pgsql/LockOrderTest.php` (pattern) — `lockingStatements(fn)` records `for update` SQL in order; run `DB_CONNECTION=pgsql DB_HOST=pgsql DB_DATABASE=testing php artisan test --group=pgsql`.
- Wayfinder (constant) — `php artisan wayfinder:generate --with-form`; without `--with-form` the `.form()` variants disappear.

## Orchestrator (user-requested, pre-P5)
- SQLite is a supported production DB: sqlite block in `config/database.php` defaults busy_timeout 5000 / WAL / synchronous normal / transaction_mode IMMEDIATE (env-overridable), guarded by `tests/Feature/SqliteTransactionModeTest.php`. Keep `lockForUpdate()` for pgsql; no sqlite-specific locking.

## Phase P5
- `ParkingSpot::scopeActive` / `isActive()` (helper) at `app/Models/ParkingSpot.php:69,74` — the one "active" definition; `Household::spots()` / `activeSpot()`. `retrieved_at` is not fillable: write it only via query-builder `update`.
- `SpotPresenter::from($spot, $household)` (helper) at `app/Support/SpotPresenter.php:17` — the one spot shape (TS `Spot` in `resources/js/types/spot.ts`); caller eager-loads `user:id,name` and passes the loaded household; lat/lng/accuracy are decimal strings.
- `ParkingSpotPolicy` (pattern) — `view`/`retrieve` `denyAsNotFound` (404). Store's only gate is `Gate::forUser($user)->authorize('parkIn', [Household::class, $locked])` under the household lock; photo stored first at `spots/{submitted household_id}/{uuid}.{ext}` on `local`, deleted in the catch.
- `ParkSheet.vue` (pattern) — `useForm` + `form.post(store().url, { forceFormData: true })`; household from Home's `household` prop, never shared `currentHousehold`.
- `useGeolocation()` / `downscale()` (helper) in `resources/js/composables/` — GeoError codes 0–3; relative times use `@vueuse/core` `formatTimeAgo`.
- `local` disk `serve => false` (constant) — `@/routes/storage` no longer generated. `LockOrderTest` has the spot-store case; partial unique index `parking_spots_one_active_per_household` verified on pgsql and sqlite.

## Phase P6
- `resources/js/pwa/caches.ts` (constant) — `CACHE` (`pages`, `inertia`, `photos`, `tiles`='osm-tiles'; no `assets`) and `USER_CACHES`; every `cacheName` and every clear reads it. SW type-checks alone: `tsconfig.sw.json` (WebWorker lib), excluded from `tsconfig.json`; `types:check` runs both.
- `useLastSpot.ts` (helper) — owns all `localStorage` (try/catch), keys `LAST_USER_KEY` / `spotKey(id)`, `STATUS_LABEL`, `writeLastSpot` (enforces unsaved protection), `markRetrieved(userId, householdId)`, `readPointerSpot()`, `parkInFlight`, `lastResponse` (banner state). Reads are reactive via an internal revision ref.
- `recordResponse(page)` (pattern) — the only writer for page-driven last-spot data, called from `app.ts` on `navigate` type `initial` and on `success`; it switches on `page.component` (`Spots/Show`, `Home`) and writes only when `generated_at` is ≤ 2 min old. Pages do not write.
- `syncDeviceUser` / `forgetUser` / `clearUserCaches` (helper) in `useDeviceData.ts` — `app.ts` calls `syncDeviceUser` on every `navigate`; the login page is `page.component === 'auth/Login'`. `/offline` (`resources/js/offline.ts`, plain DOM) never syncs or clears.
- Shared prop `generated_at` (constant) — `Inertia::always(...)`, ISO; `config('inertia.history.encrypt')` true; logout calls `Inertia::clearHistory()` after `invalidate()`/`regenerateToken()` (session key `inertia.clear_history`).
- Frontend unit tests (pattern) — `resources/js/composables/__tests__/*.test.ts`, `import … from 'vite-plus/test'`, `vi.stubGlobal` for `localStorage`/`caches`; run `npx vp test run`. No npm script or CI step runs them yet.

## Phase P7
- `npm run test:js` (`vp test run`) is the frontend unit-test entry; CI runs it in `.github/workflows/tests.yml` before `composer ci:check`. `DatabaseSeeder` seeds `test@example.com` (`google_id` null) with an active spot; the app queues nothing and schedules nothing (invite mail is sent synchronously).
- TEMPORARY sign-in allowlist [USER-2026-10-28]: `config/services.php` google.allowed_emails (env GOOGLE_ALLOWED_EMAILS only; unset/empty = nobody, "*" = anyone; phpunit sets "*"), checked in `GoogleAuthController::callback()` after the verified check; tests `tests/Feature/Auth/GoogleAllowlistTest.php`. Removal steps in README.
- Acceptance fixes [ORCH-ACC]: `app.ts` pageshow persisted → hide + reload (bfcache leak after logout, FM9); update toast `duration: Infinity` (FM13).
