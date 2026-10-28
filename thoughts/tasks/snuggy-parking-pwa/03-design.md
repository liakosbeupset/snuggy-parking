# 03 — Design: Snuggy Parking PWA

Reads: `01-questions.md`, `02-research.md`.

`file:line` refs without a URL point into the reference scaffold `laravel/vue-starter-kit@dev-main` described in `02-research.md`. A **fresh** copy of that scaffold becomes the repo's starting tree. The reviewed scratch copy holds spike edits and is never copied.

**Revision history.** Finding ids `[Fn]` refer to `03-design-reconciled.md`.

| Rev | Change |
|---|---|
| 2 | Absorbs the first review round, F1–F38. |
| 3 | Added an installed-app login handoff, an HTTPS tunnel and invite cancellation. |
| 5 | Third review round (F77+), all mechanical:<br>• SW switched to `injectManifest` with a hand-written `sw.ts`<br>• SSR off<br>• history encryption<br>• `prompt=select_account`<br>• one global lock order<br>• mail timeout set in `config/mail.php`<br>• offline page reads a last-user pointer and is exempt from clearing<br>• pending-spot safety net for the park POST<br>• no supervisor override<br>• `node_modules` in a named volume<br>• kit pinned to `c290133`<br>• AC22 no longer promises an offline map (OSM policy) |
| 4 | Follows the second review round, F39+. **Removes the handoff**: it was an account-takeover vector [F39], and the user chose option A on 2026-10-27. Keeps the tunnel and makes the real-iPhone sign-in check a gate-before checkpoint. Keeps invite cancellation. Adds a device-local last-spot fallback and an offline page [F44]. The user also approved Laravel 13 / Inertia 3 [F51]. |

## Chosen Approach

**Build on the current Laravel Vue starter kit (Laravel 13, Inertia 3, Vue 3 + TS, Tailwind 4, Wayfinder, Vite+), remove Fortify completely, and add Socialite as the only auth. Add the domain on top. Run everything in a Sail-generated Docker stack.**

### 1. Scaffold and bootstrap [F3, F23, F24, F34, F52]

1. Create the kit with host Laragon PHP 8.4, the only PHP available before the container exists:
   `composer create-project laravel/vue-starter-kit <tmp> "dev-main#c290133c255626a39d85c94bf9d3c2f7f55a9add" --no-scripts`.
   - This pins the kit commit that `main` pointed at on 2026-10-27 (`git ls-remote`), so the fresh tree matches the research citations [F84].
   - If that ref cannot be resolved, use `dev-main` and record the resolved SHA in the phase report. Re-check every `file:line` this design cites against it.
   - Move the tree into the repo, keeping the existing `.git`, `README.md` and `thoughts/`.
   - Create `.env` from `.env.example`, then run `php artisan key:generate`. `--no-scripts` skipped both.
2. Merge the kit's `.gitignore` with ours. Drop its `/CLAUDE.md` and `/AGENTS.md` ignore lines.
3. Strip the kit's auth features: `LARAVEL_INSTALLER_NO_NODE=1 php artisan install:features --answers='{"auth_features":[]}'`.
   - The command accepts non-interactive answers (InstallFeaturesCommand.php:20-21).
   - With that env var it skips the host npm steps (chisel.php:42).
   - It strips registration, email verification, 2FA, passkeys and password confirmation (chisel.php:88-106).
   - Chisel itself also does the following, so the phase verifies these outcomes rather than repeating them (chisel.php:287-300):
     - removes the `post-update-cmd` hook;
     - deletes `InstallFeaturesCommand.php`, `chisel.php` and `chisel-paths.php`;
     - runs `composer lint` and `wayfinder:generate` with host PHP;
     - removes npm packages from `package.json` but not from `package-lock.json`. The lockfile is re-synced by the first in-container `npm install` [F85].
4. Remove what chisel cannot:
   - `User.php`: the `MustVerifyEmail`/`PasskeyUser` interfaces and the `PasskeyAuthenticatable`/`TwoFactorAuthenticatable` traits.
   - `settings/Layout.vue`: the Security nav item and its `@/routes/security` import.
   - `Profile.vue`: the `<DeleteUser />` usage.
   - `config/inertia.php`: `ssr.enabled => false`. Also drop the `build:ssr` script. Nothing here is SSR-safe — Leaflet, `localStorage`, Workbox — and production never builds an SSR bundle [F77].
   - `.github/workflows/tests.yml`: move to PHP 8.4 and keep `composer setup && composer ci:check` [F89].
   - **Keep** `appearance.edit` and move it to the `auth` group. It sits inside the routes/settings.php:17-31 block being removed [F92].
   - Fortify: the package, `FortifyServiceProvider` (bootstrap/providers.php:6), `config/fortify.php`, `app/Actions/Fortify`, `PasswordValidationRules`.
   - Password UI: the login and reset pages, the settings Security/password routes and controller (routes/settings.php:17-31), `DeleteUser`, `PasswordInput`.
   - Kit pages that no longer exist here: `Welcome.vue`, `Dashboard.vue`, and their `dashboard`/`home` route usages in `AppHeader.vue`, `AppSidebar.vue` and the auth layouts.
   - Every `email_verified_at`/`password` reference:
     - `ProfileController::update`
     - `ProfileUpdateRequest` and the `ProfileValidationRules` email rules
     - `Profile.vue` `mustVerifyEmail`
     - `types/auth.ts`
     - `UserFactory`
     - `config/auth.php`'s `passwords` broker
     - the `Password::defaults` block in `AppServiceProvider`
     - `tests/TestCase.php` `skipUnlessFortifyHas`
   - Their tests.
   - Then remove `laravel/chisel`, `chisel*.php`, `InstallFeaturesCommand`, and every composer hook that calls it: `post-update-cmd` (composer.json:81), `post-create-project-cmd`, and `extra.laravel.installer`.
   - Adapt `setup` to the container.
   - Check `package.json` for any dependency belonging to a removed feature that chisel did not own (chisel.php:58-63).
5. Generate Docker config: `php artisan sail:install --with=pgsql,mailpit --php=8.4`.
6. Start the stack with `docker compose up -d`. `node_modules` is never installed on the host. From then on every `composer`, `npm` and `artisan` command runs via `docker compose exec laravel.test …` (KD6).

### 2. Auth [F4, F5, F8, F16, F21, F28, F31, F39, F40, F48, F49]

- `laravel/socialite ^5.31`, with a `google` entry in config/services.php:
  - `redirect` is the relative `/auth/google/callback`, resolved against the current request.
  - A `guzzle` option sets `['timeout' => 10, 'connect_timeout' => 5]`. That passthrough must be confirmed against the installed SocialiteManager during Implement; if it doesn't exist, use the documented equivalent.
- **Routes:**
  - `GET /login` renders Inertia `auth/Login`. It is **named `login`**, which the `auth` middleware redirects to. It sits behind `guest`.
  - `GET /auth/google/redirect` and `GET /auth/google/callback` sit behind `guest` and the **named** limiter `RateLimiter::for('google-oauth')`: 20/min keyed `'google-oauth|'.$request->ip()`.
    - One sign-in spends two hits, so the limit is 10 sign-ins/min per IP.
    - Named limiters are used for a readable per-purpose key. An unnamed `throttle:N,M` without a `$prefix` keys guests by `domain|ip` only, so differently-limited routes would share one counter (ThrottleRequests.php:224-230) [F86].
  - The redirect calls `Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect()`. Google then always shows its account chooser, so a second person on a shared device — or an invitee who must switch accounts — can pick a different account [F79].
  - `POST /logout` is named `logout`.
- **Login UI.** "Continue with Google" is a plain `<a href="/auth/google/redirect">`, a full same-window navigation, in browser tabs and installed apps alike. It is never a popup or `window.open`.
- **The callback:**
  1. Catches `InvalidStateException`, `?error=`, and any Socialite or HTTP exception. Each redirects to `/login` with an error flash (FM5).
  2. Requires `($googleUser->user['email_verified'] ?? false) === true` (FM4, I8). A missing key never warns or 500s [F49].
  3. **Looks up the user by `google_id`.**
     - With no match, it falls back to a user with the same email **only when that user's `google_id IS NULL`** (seeded or factory dev users only), and **sets `google_id` on that row** [F48].
     - If the email belongs to a user with a *different* non-null `google_id`, login is **refused** (FM16).
  4. Refreshes `email` and `avatar` from Google on every login. `name` is set only on creation. A refresh that would collide with another user's email is refused, as in step 3.
  5. Creates a new user and their personal household in one transaction. If a parallel callback hits the unique constraint, `UniqueConstraintViolationException` is caught **outside** the transaction and the lookup is retried once (FM17). Inside an aborted PostgreSQL transaction the retry would fail [F50].
  6. Calls `Auth::login($user)` with **no remember-me**. The recaller requires a string password hash (SessionGuard.php:234-238).
     - "Stay signed in" is a 30-day session instead: `config/session.php` `lifetime` defaults to `env('SESSION_LIFETIME', 43200)`, and `.env.example` sets `43200`. The default lives in config, not only in `.env` [F53].
  7. Runs `session()->regenerate()`, then `redirect()->intended('/')`.
- **Logout and Inertia history [F78].**
  - `config/inertia.php` sets `history.encrypt => true`. Inertia keeps each page's props in `history.state` and restores them on Back without a request (@inertiajs/core dist/index.js:1768-1790); encryption makes those copies unreadable once the history key is rotated.
  - Logout calls `Inertia::clearHistory()` (ResponseFactory.php:189) before redirecting.
  - Back after logout cannot restore a previous user's page props.
  - Logout is a full-page form POST (`<form method="post">`), not an Inertia `<Link>`, so the document is replaced.
- **Email is never user-editable.** `email`, `google_id` and `avatar` are not fillable; the callback writes them with `forceFill`. The Profile request accepts `name` only.
- **Installed apps on iOS** [F15, F39]. Whether a same-window redirect to `accounts.google.com` keeps the session inside the home-screen app is verified on a real iPhone through the tunnel (§9). That check is **AC21, a gate-before checkpoint in Implement**.
  - If it fails, a dedicated follow-up task designs a safe handoff, for example a sign-in code the user types into the app. It gets its own review. Nothing of the withdrawn rev-3 handoff is built.

### 3. Schema [F7, F19]

The repo is greenfield and never deployed, so the kit's original migrations are edited (KD1).

- **`users`.**
  - Remove `password`, `email_verified_at`, the 2FA and passkey artefacts, and the `password_reset_tokens` table. Keep `remember_token`, which is a harmless framework column.
  - Add `google_id` (nullable, unique) and `avatar` (nullable).
- **`households`.** `id`, `name`, `owner_id` (FK users, restrict delete), timestamps.
  - The **same migration** adds `users.current_household_id` (nullable FK → households, nullOnDelete) via `Schema::table('users', …)`. PostgreSQL rejects a FK to a table that does not exist yet.
- **`household_user`.** `household_id` FK cascade, `user_id` FK cascade, timestamps, unique(`household_id`, `user_id`).
- **`household_invites`.** `id`, `household_id` FK cascade, `email` (lowercased), `token` (64 chars, unique), `invited_by` FK users, `expires_at`, `accepted_at` nullable, `accepted_by` nullable FK users, timestamps.
- **`parking_spots`.**
  - Columns: `id`, `household_id` FK cascade, `user_id` FK users (nullable, nullOnDelete), `lat` decimal(10,7), `lng` decimal(10,7), `accuracy` decimal(8,2) (validated ≥ 0), `note` text nullable, `photo_path` nullable, `parked_at`, `retrieved_at` nullable, timestamps.
  - Index on (`household_id`, `parked_at`).
  - **Partial unique index `parking_spots_one_active_per_household` ON (household_id) WHERE retrieved_at IS NULL.** It is raw SQL, valid on PostgreSQL and SQLite.
  - Casts: `lat`/`lng` `decimal:7`, `accuracy` `decimal:2`, dates `datetime`. Output is identical strings on both drivers [F18].
- **Verification includes `migrate:fresh` against the pgsql container** (R7).

### 4. Authorization [F6, F10, F17, F37, F38, F45, F46, F47]

All app routes are behind `auth`. Policies plus one current-household accessor guard every reachable boundary.

- **`ParkingSpotPolicy::view|retrieve`.** Allowed when the user is a member of the spot's household; otherwise `denyAsNotFound()`.
- **`HouseholdPolicy`:**
  - `view`: any member.
  - `invite`, `revokeInvite`: the owner.
  - `removeMember`: the owner, and the target is not the owner.
  - `leave`: a member who is not the owner.
  - `switchTo`: any member.
  - `parkIn`: any member.
- **`User::currentHousehold(): Household`.**
  1. Returns the household of `current_household_id` if the user is still a member.
  2. Otherwise falls back to the household the user owns (lowest id if several).
  3. Otherwise creates the personal household (`CreatePersonalHousehold`, the same action first login uses). Creation runs in a transaction that locks the user row (`lockForUpdate`) and re-checks for an owned household first, so two parallel requests cannot create two [F46].
  4. **Both fallbacks persist** `current_household_id` [F45]. `CreatePersonalHousehold` itself also sets `current_household_id`. Tests that create extra households set `current_household_id` explicitly and never rely on the "lowest id" fallback [F91].
  - This is a write on a GET path, which is deliberate. It happens at most once per stale pointer, and it is the only GET that writes.
  5. The result is memoised per request.
  - It is the only reader of the current household. Shared props pass it as a **lazy closure**, so non-Inertia responses (photo, `/sw.js`) never evaluate it.
  - `UserFactory` gets an `afterCreating` hook calling `CreatePersonalHousehold` (I7).
- **Mutations lock and re-check.** Spot store, member removal, leave, invite accept and invite cancel each lock the household row inside their transaction and re-check membership or ownership **after** the lock.
- **One global lock order [F80]: household row first, then invite row.**
  - Accept reads the invite **unlocked** by token to learn its `household_id`, locks the household, then locks and re-reads the invite.
  - Cancel locks the household, then locks the invite.
  - No path locks an invite before its household, so accept and cancel cannot deadlock.
- **Eager loading [F90].**
  - Home's last 10 spots load `user`. The member list loads members. Pending invites load `inviter`.
  - `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider`, so an N+1 fails tests instead of shipping.
- **Photo route.** `GET /spots/{spot}/photo` authorizes `view` and streams from the `local` disk with `Cache-Control: private, no-store`. It returns 404 if `photo_path` is null or the file is missing.
  - `no-store` keeps the photo out of the browser's HTTP cache. The SW `photos` cache stores it deliberately, because the Cache API ignores `Cache-Control`, and that cache is cleared per FM9 [F55].
- **`local` disk `'serve' => false`** (config/filesystems.php:36).
- **Invite page exposure.** `GET /invites/{token}` shows household name and inviter **only when the invite email matches the logged-in user**. Others see "This invite was sent to a different email address", with no part of the address [F57]. Unknown tokens return 404.
- **Household page props.** `pendingInvites` (tokens and links) are included **only for the owner**. Members get no invite data [F58].

### 5. Parking flow [F9, F36, F47]

- **`POST /spots`** takes multipart `household_id`, `lat`, `lng`, `accuracy`, `note?`, `photo?`.
  - `household_id` is the household Home displayed when the user tapped the button. It is a hidden prop, so the spot can never land in a different household than the one shown.
- **Validation:**
  - `household_id` exists, and `parkIn` is authorized for it.
  - `lat` numeric −90..90; `lng` numeric −180..180; `accuracy` numeric 0..100000.
  - `note` ≤ 500.
  - `photo`: `image|mimes:jpeg,png,webp|max:5120`.
- **Store the photo first:** `storeAs("spots/{household_id}", Str::uuid().'.'.$photo->extension())`. The extension comes from the server-detected MIME type. A `false` return throws (FM3).
- **Then, in one DB transaction:**
  1. Lock the household row.
  2. Re-check membership. If it fails: 403, the photo is deleted (FM18).
  3. Set `retrieved_at = now()` on the active spot.
  4. Insert the new spot with `parked_at = now()`.
- On any exception, delete the stored photo and rethrow (FM2).
- Redirect to `spots.show`.
- **`PATCH /spots/{spot}/retrieve`** sets `retrieved_at` if null. It is idempotent and redirects home.

### 6. Invites and membership [F29, F30, F47, F56]

- **`POST /households/{household}/invites`** (owner; named limiter `invites`, 20/min keyed by user id):
  - Validates the email and refuses one that already belongs to a member.
  - Creates the invite with a 7-day expiry and a `Str::random(64)` token.
  - Sends `HouseholdInviteMail` synchronously inside try/catch, with an SMTP timeout of 5 s.
    - The timeout is set in `config/mail.php` as `'timeout' => env('MAIL_TIMEOUT', 5)`. The kit hardcodes `null` (config/mail.php:48), so an `.env` value alone would do nothing [F81].
  - If the mail fails, the invite is kept and a warning flash is shown (FM6). The link is always copyable from the pending list.
- **`DELETE /households/{household}/invites/{invite}`** cancels an invite (owner; scoped binding).
  - A pending invite is deleted, so its link 404s afterwards.
  - An accepted or expired invite: redirect back with an error flash ("Only pending invites can be cancelled") and no change.
  - A non-owner gets 403. An invite of another household returns 404.
- **`GET /invites/{token}`** (auth): see §4.
- **`POST /invites/{token}/accept`** runs in a transaction using the global lock order (read the invite unlocked, lock the household, then lock and re-read the invite):
  1. If the row is gone (cancelled): 404, "invite no longer valid".
  2. If accepted, expired, or `lower(user.email) !== invite.email`: rejected.
  3. Otherwise `syncWithoutDetaching`, set `accepted_at`/`accepted_by`, and set the user's `current_household_id`.
- **`DELETE /households/{household}/members/{user}`** (owner).
  - The target must currently be a member, otherwise 404.
  - Removes the pivot row.
  - Clears the target's `current_household_id` **only if it equals this household** [F56]. Their next request self-heals through `currentHousehold()`.
- **`POST /households/{household}/leave`** (non-owner member). Same rule for `current_household_id`; redirects to `/`.
- **`PUT /current-household`**.
  - Payload: `{household_id}`.
  - A non-member gets 403 via `switchTo`; a missing id is a 422 validation error.
  - On success it sets `current_household_id` and redirects to `/` [F58].
- **Pending invites** are `accepted_at IS NULL AND expires_at > now()`, defined in one `pending()` scope.
- **Invite links opened outside the installed app.** On iOS a Mail link opens Safari, so acceptance happens there. Membership and `current_household_id` are server-side, and the installed app picks them up on its next request.
- **Guests at an invite link** go to `/login`. `url.intended` survives the same-window Google round-trip.

### 7. Screens [F11, F42, F44]

All screens are Inertia pages using the header layout variant (AppHeaderLayout).

**Shared props:**
- `auth.user`: `{id, name, email, avatar}`.
- `currentHousehold`, lazy.
- `households` for the switcher, lazy.
- `generated_at`: an ISO server timestamp.

**`Home`:**
- **"I parked here" button.**
  - Tapping it runs Geolocation `getCurrentPosition({enableHighAccuracy:true, timeout:15000, maximumAge:0})`.
  - A confirm sheet then shows:
    - "±N m", with a warning above 50 m;
    - an optional note;
    - an optional photo (`accept="image/*" capture="environment"`), downscaled on a canvas to ≤1600 px JPEG at ≈0.8 quality. If decoding fails, the original is sent.
  - Then it POSTs with the displayed `household_id`.
  - Errors for denied, unavailable and timeout each show a Retry (FM10).
- **Pending-spot safety net [F82]** — for the weak-signal, expired-session or 419 case.
  1. **On submit**, before the request leaves, `useLastSpot` stores the captured spot as `{…, status: 'unsaved', captured_at}`. The photo is excluded.
  2. **While waiting**, the sheet shows "Saving…".
     - After 20 s without a response, it switches to "Still trying — your location is kept on this device", with Retry and Cancel.
     - Inertia `onNetworkError` and any 4xx/5xx other than validation show "Not saved — Retry". The default network-error handler is a no-op (core dist/index.js:3783), so this is set explicitly.
  3. **A fresh `Spots/Show` response replaces the unsaved entry** with the saved spot.
  4. **If the session expired** (419, or a redirect to `/login`): after sign-in, Home shows "You have an unsaved spot from {time} — Save now / Discard". Save now resubmits lat, lng, accuracy and note with the current household. The photo has to be re-added.
  5. **The unsaved entry survives the login page.** That page clears the SW caches and the saved-spot entries of *other* users, but keeps an `unsaved` entry for the last user id. The entry only ever holds what this device's user captured themselves.
- Active-spot card, and the last 10 spots.

**`Spots/Show`:**
- Leaflet map: OSM tiles with `crossOrigin: true` and attribution, a marker and an accuracy circle. The default icon is fixed via Vite asset imports.
- Note, photo, who parked and when.
- "Get directions": an `<a>` to the server-computed `directions_url`, `https://www.google.com/maps/dir/?api=1&destination={lat}%2C{lng}`.
- "Got the car" when the spot is active.

**`Household`:**
- Members, with an owner badge.
- Owner only: the invite form; pending invites with a copyable link and "Cancel invite"; remove.
- Members: leave.

**Other pages:**
- `Invites/Show`.
- `auth/Login`: the Google link and an error flash.
- Settings: Profile (name only; email shown read-only) and Appearance.
- Household switcher: shown when the user belongs to more than one household.

**Saved-copy banner [F11, F42, F87].**
- `generated_at` is shared with `Inertia::always()`, so it is resent on partial reloads too. Without that, a fresh partial response would carry a stale timestamp.
- Evaluated **only on the initial page load and on Inertia `success` events**, i.e. responses from the server or the SW. It is never evaluated on history restores (Back), so Back never shows it.
- If `generated_at` is more than 2 minutes before the client clock, it reads "Showing a saved copy from {date + time} — may be outdated", with Refresh.
- The date is shown unless it is today.
- Phone clock drift beyond 2 minutes can mis-trigger it. This is accepted: phones are NTP-synced (R10).

**Device-local last spot [F44, F83].**
- The composable `useLastSpot` writes to `localStorage` under a key per user id. It stores `{spot id, household name, lat, lng, accuracy, note, parked_at, parked_by, directions_url, status: saved|unsaved|retrieved, saved_at}` whenever:
  - `Spots/Show` renders a fresh response;
  - Home renders its active spot;
  - **a fresh Home for that household has no active spot**: the entry is marked `retrieved`, so a car someone already collected is never shown as current;
  - the park form submits, writing an `unsaved` entry (above).
- A pointer `snuggy:last-user` (the user id) is written whenever an authenticated page renders. `offline.ts` reads it to find the right key, since `/offline` has no session.
- On a stale Home (the banner is showing) or on the offline page, if the stored spot was saved later than the page's `generated_at`, a "Last spot you saw" card leads with it: the map (cached tiles only), note and directions link.
- Cleared together with the user caches (FM9).

**Offline page.**
- `GET /offline` is a static Blade view: no session, no data. It has its own small Vite entry, `resources/js/offline.ts`.
- It renders the device-local last spot via the `snuggy:last-user` pointer: coordinates, accuracy, note, who/when, the directions link and "Map unavailable offline". Otherwise it shows "No saved spot on this device". A Retry link is included either way.
- **No map.** The OSM tile policy says "Offline use is not permitted" (02-research §OSM) [F88].
- `resources/js/offline.ts` is added to `laravel({ input: [...] })` in `vite.config.ts`, so the build emits it into the Vite manifest [F93].
- **`/offline` never clears device data.** Clearing on a no-user page applies **only to `auth/Login`** [F83].
- It is precached and used as the navigation fallback (§8).

### 8. PWA [F1, F2, F12, F13, F14, F20, F26, F43, F44, F54, F55]

**vite-plugin-pwa (`strategies: 'injectManifest'`) [F94].**
- The SW is hand-written: `resources/js/sw.ts`. It needs a custom offline fallback, header-based matchers, redirect filtering and route exclusions. `generateSW` would serialize plugin functions into the template, where they cannot reference Workbox imports (workbox-build runtime-caching-converter.js:33-35).
- Plugin options:
  - `srcDir: 'resources/js'`, `filename: 'sw.ts'`.
  - `outDir: public/build`, `buildBase: /build/`.
  - `injectRegister: null`, `registerType: 'prompt'`, `manifestFilename: manifest.webmanifest`.
  - `injectManifest: { globPatterns: ['**/*.{js,css,woff2,svg,png}'], modifyURLPrefix: {'': '/build/'} }`. The glob covers Leaflet's marker PNGs and the `offline.ts` chunk.
- `sw.ts` imports `workbox-precaching`, `workbox-routing`, `workbox-strategies`, `workbox-expiration` and `workbox-cacheable-response`. These and `workbox-window` are **explicit** `dependencies` in `package.json`.
- `sw.ts` behaviour:
  - `precacheAndRoute([...self.__WB_MANIFEST, { url: '/offline', revision: __OFFLINE_REV__ }])`. `__OFFLINE_REV__` is a Vite `define` set to the build timestamp, so every deploy refreshes `/offline` and its chunk references [F95].
  - `cleanupOutdatedCaches()`.
  - A `message` listener for `SKIP_WAITING`. There is no automatic `skipWaiting` or `clientsClaim`.
- **Verify `injectManifest` builds under Vite+ at the start of the PWA phase** (R12). Fallback: `generateSW`, with plugin bodies written self-contained (`caches.match(...)` rather than imported helpers).

**`/sw.js` and registration:**
- `GET /sw.js` streams `public/build/sw.js` with `Content-Type: text/javascript; charset=utf-8` and `Cache-Control: no-cache`, or returns 404 without a build.
- It is registered through `withRouting(then: …)` in `bootstrap/app.php`, **outside the `web` group**. Every route in `routes/web.php` gets the `web` group [F54].
- `GET /offline` is registered the same way.
- **Registration:**
  - Manual, from `app.ts`, in production only: `new Workbox('/sw.js', { scope: '/' })` (workbox-window).
  - Never `virtual:pwa-register`, which registers `/build/sw.js` (dist/index.js:166).
  - In dev (`!import.meta.env.PROD`), `app.ts` unregisters any existing registration and deletes all caches, so a build tested on `localhost` cannot haunt later dev sessions [F43].
- **Updates.**
  - On `waiting`, a toast offers "Update available — Reload". Clicking it posts `SKIP_WAITING` and reloads on `controlling`.
  - The page is never force-reloaded.

**Manifest** (`/build/manifest.webmanifest`):
- `name` "Snuggy Parking", `short_name` "Snuggy", `id` `/`, `start_url` `/`, `scope` `/`, `display` standalone, theme and background colours.
- Root-absolute icon paths, `/icons/icon-192.png`, `/icons/icon-512.png` and `/icons/icon-maskable-512.png`, committed under `public/icons/`.
- Linked from `app.blade.php`, which also adds `theme-color`, apple meta tags and an apple-touch-icon.

**Never intercepted [F96].** These paths fall through to the network with no SW handling, cache or fallback:
- `/auth/*` (the OAuth redirect and callback must never time out into a fallback);
- `/login`, `/logout`;
- `/invites/*`;
- `/sw.js`;
- every non-GET request.

**Every caching rule** uses a `cacheWillUpdate` plugin that stores a response only when `status === 200 && !response.redirected` [F97]. A followed redirect — for example an expired session landing on login JSON, or a mutation's redirect-follow — is never stored under the original URL.

**Runtime caching.**

| What | Strategy | Cache | Limits |
|---|---|---|---|
| Navigations | NetworkFirst, `networkTimeoutSeconds: 3` | `pages` | 30 |
| Inertia GETs: `X-Inertia` and **no** `X-Inertia-Partial-Component` header [F26, F41] | NetworkFirst, 3 s | `inertia` | 30 |
| `/spots/*/photo` | NetworkFirst, `networkTimeoutSeconds: 3` | `photos` | 10 |
| `/build/assets/*` | CacheFirst | `assets` | 100 entries, 30 days |
| OSM tiles | CacheFirst | `osm-tiles` | 200 entries, 7 days, only tiles viewed |

- **Navigations.** On network failure the response comes from `pages`; on a cache miss, the precached `/offline` is served via a `handlerDidError` plugin [F44].
- **Inertia GETs.** Isolation from navigations comes from the **separate named cache**; there is no key-suffix plugin [F41]. Partial reloads, whether `only` or `except`, are never cached, because both carry `X-Inertia-Partial-Component`.
- **Assets.** These are hashed chunks still referenced by cached HTML after a deploy.

**User-scoped data.** `pages`, `inertia` and `photos`, plus `useLastSpot` entries, are deleted client-side:
- **Logout**, before the POST: everything, including this user's unsaved entry. Logging out is an explicit "forget me here".
- **`auth.user.id` differs from the `snuggy:last-user` pointer:** everything belonging to the previous user.
- **The `auth/Login` page** (the only no-user page that clears):
  - the SW caches;
  - saved and retrieved last-spot entries;
  - **except** an `unsaved` entry of the pointer user (§7 safety net).

  A new person must pass through `/login` before any authenticated page loads.
- **`/offline` never clears.**

### 9. Docker [F59, F60]

- **Services in `compose.yaml` (generated by Sail):**
  - `laravel.test`: PHP 8.4, `APP_PORT` 80, `VITE_PORT` 5173.
    - It sets `PHP_CLI_SERVER_WORKERS=4` and **nothing else**.
    - Sail's supervisor already sets `LARAVEL_SAIL=1` (runtimes/8.4/supervisord.conf:10), so `ServeCommand` honours several workers without `--no-reload` (ServeCommand.php:117-119) [F60, F98].
    - The Dockerfile's `SUPERVISOR_PHP_COMMAND` keeps `-d variables_order=EGPCS`, which passes the env to the workers. It must not be overridden.
  - A **named volume** `node_modules` mounted at `/var/www/html/node_modules` [F99].
    - This keeps Linux-native binaries and `.bin` symlinks off the NTFS bind mount.
    - The host has no `node_modules`. The IDE resolves types through the container, or the developer runs `npm ci` on the host for editor tooling only; that host copy is never used to build or run.
  - `pgsql`: postgres:18-alpine.
  - `mailpit`.
- **Vite in the container.** `vite.config.ts` gets `server: { host: '0.0.0.0', port: 5173, hmr: { host: 'localhost' } }` so the host browser reaches the dev server [F59].
  - The dev server is started with `docker compose exec laravel.test npm run dev`. There is no separate Vite service; the Sail convention fills the "Vite" item of the agreed stack [F100].
- **Dependencies** are installed inside the container.
- `.gitattributes` sets `* text=auto eol=lf`.
- **`.env`:** `DB_CONNECTION=pgsql`, `MAIL_MAILER=smtp` (mailpit:1025, timeout 5), `APP_URL=http://localhost`, `SESSION_LIFETIME=43200`.
- **Tests** run on in-memory SQLite (phpunit.xml:37-50).
- **Optional HTTPS tunnel (AC21).**
  - An `ngrok` service under `profiles: [tunnel]`, pinned to a concrete `ngrok/ngrok` 3.x tag.
  - Command: `http --url=${NGROK_DOMAIN} laravel.test:80`. Env: `NGROK_AUTHTOKEN`.
  - Start it with `docker compose --profile tunnel up -d`.
- **Proxy trust [F40].**
  - `config/trustedproxy.php` with `'proxies' => env('TRUSTED_PROXIES')`. The middleware reads `config('trustedproxy.proxies')` at request time (TrustProxies.php:69).
  - No `trustProxies(at: env(...))` in `bootstrap/app.php`: that callback runs before `.env` is loaded.
  - Unset by default, so no proxy is trusted. The tunnel recipe sets `TRUSTED_PROXIES=*` and `APP_URL=https://<domain>`.
- Tunnel testing uses a production build with the Vite dev server stopped.
- **README covers:**
  - the bootstrap;
  - the Google OAuth client, with both redirect URIs;
  - `docker compose exec` usage;
  - the tunnel recipe, including installing on iPhone and Android;
  - production upload limits [F27];
  - production `TRUSTED_PROXIES`. Behind a load balancer, without it the OAuth limiter sees one IP, and the callback URL comes out as `http` [F101].
- **Migration rollback.** The `households` migration's `down()` drops `users.current_household_id` (FK first) before dropping `households` [F102].

### 10. Tests

- Pest 4 + `pest-plugin-laravel`. Kit tests are converted to Pest.
- Fakes: `Socialite::fake()`, plus `Mail::fake()` and a failing mailer.
- `composer test` runs pint, phpstan level 7 and the tests.
- `npm run check` and `npm run types:check` run in the container.
- `tests/TestCase.php` calls `$this->withoutVite()` in `setUp`, so the suite never needs a frontend build. AC15 runs without `npm run build` [F103].
- Route tests:
  - **`/sw.js`.** The file path comes from `config('pwa.sw_path')` (default `public_path('build/sw.js')`). Tests point it at a temp file, or at a missing path, to cover both 200 and 404 without touching a real build.
  - **`/offline`** renders without a session.
  - **OAuth limiter.** The 21st hit within a minute returns 429.
  - **Invite limiter.** Over-limit requests return 429.

## Behavior Contract

**Intentional behavior change** (greenfield). Externally observable deltas from the stock kit:

- **Home and login.**
  - `/` is the authenticated Home. Welcome and `/dashboard` are removed, and guests are redirected to `/login` (route name `login`).
  - `/login` shows only "Continue with Google", a same-window redirect. `POST /login` returns 405.
  - 404: `/register`, `/forgot-password`, `/reset-password/*`, `/two-factor-challenge`, `/user/confirm-password`, `/email/verify*`, `/settings/security`, `/settings/password`, passkey routes, and `/.well-known/passkey-endpoints`.
- **Settings.**
  - `settings/profile` and `settings/appearance` require only `auth`; `verified` no longer exists. `/settings` still redirects to profile.
  - Account deletion is removed.
- **Account data.**
  - Email is never editable. It mirrors the Google account and is refreshed each login.
  - Name comes from Google at sign-up and is editable afterwards.
  - Location history and photos are retained indefinitely. There is no deletion path in this task [F61].
- **Sessions and login.**
  - Sessions last 30 days of inactivity. This is the config default, with no remember cookie.
  - The OAuth routes are limited to 10 sign-ins/min per IP (20 hits), by their own limiter.
  - Invite creation is limited to 20/min per user.
  - Both show 429 when exceeded.
  - Google always shows its account chooser (`prompt=select_account`).
  - Back after logout shows nothing from the previous session (history encryption + `clearHistory`).
  - Login is refused for an unverified Google email, and for a Google account whose email belongs to a different linked Google account.
- **New routes.**
  - `/auth/google/redirect` and `/auth/google/callback`
  - `/spots` (POST), `/spots/{spot}`, `/spots/{spot}/retrieve` and `/spots/{spot}/photo`
  - `/household`, `/households/{household}/invites` (POST), `/households/{household}/invites/{invite}` (DELETE), `/households/{household}/members/{user}` (DELETE), `/households/{household}/leave` (POST) and `/current-household` (PUT)
  - `/invites/{token}` and `/invites/{token}/accept`
  - `/sw.js` and `/offline`
- **Households.**
  - `/household` takes no parameter. It always shows the **current** household; to see another, switch first [F104].
  - A removed member's past spots, notes and photos stay in the household, and the member loses access to them [F105].
- **Storage.** `/storage/{path}` (the local disk's signed serve route) no longer exists.
- **Invites.**
  - `/invites/{token}` reveals household and inviter only to the invited address.
  - Anyone else gets a 200 page saying the invite was sent to a different address, with "Sign in with a different Google account". That logs out and returns to `/login`, where `prompt=select_account` lets them pick another account.
  - Cancelling a non-pending invite redirects back with an error message.
  - Owners can cancel pending invites.
  - Members never receive invite tokens in page props.
- **Dev tooling.** The optional `tunnel` compose profile exposes the app over HTTPS. Proxies are trusted only when `TRUSTED_PROXIES` is set.
- **PWA (production builds only).**
  - The app is installable.
  - Offline or on networks slower than 3 s, previously seen pages are served from cache with a dated "saved copy" banner, and the last spot seen on the device is shown.
  - With nothing cached, an offline page shows that last spot without a map.
  - A park attempt that does not complete keeps the captured location on the device, offering Retry or Save after sign-in.
  - Opening `/login` (for example after the 30-day session expires) clears the device's cached pages and saved last spot. Only an unsaved park attempt survives.
  - App updates are offered by a toast, never forced.

## Invariants

- **I1.** A user can read or act on a household's spots, spot photos, members or invites only if they are a member of that household. → AC7, AC9.
  - **Named exception:** an authenticated user holding an invite token for *their own* email sees that household's name and inviter before joining.
- **I2 (AC16).** At most one spot with `retrieved_at IS NULL` per household, enforced by a partial unique index.
- **I3 (AC17).** Every use of "current household" resolves to a household the user is currently a member of. A stale `current_household_id` never authorizes anything, and spot creation never uses it at all: it authorizes the submitted `household_id` instead.
- **I4.** Every household has exactly one owner: `households.owner_id`, non-null (schema).
  - The owner is also a member. This is maintained by application code — the creation transaction, plus policies that forbid the owner from leaving or being removed. It is not a database constraint.
- **I5.** An invite grants membership at most once, only before `expires_at`, only while it exists (not cancelled), and only to an authenticated user whose stored email equals the invite email case-insensitively. → AC10, AC11, AC20.
  - The stored email is the verified Google email **as of that user's last login** (I8). With sliding 30-day sessions, it can lag a later change at Google (R13) [F106].
- **I6.** Spot photos are reachable only through the authorizing photo route. → AC12.
- **I7.** Every user owns and belongs to at least one household. Guaranteed by:
  - first-login creation and the factory hook;
  - the locked self-heal in `currentHousehold()`;
  - there being no household deletion.
- **I8 (AC18).** A user row is created or logged into only through a Google account with a verified email.
  - `users.email` equals that account's email as of its last login, and users cannot edit it.
  - A Google account never takes over a row linked to a different `google_id`.
- **I9.** *Withdrawn in rev 4*, together with the handoff it described.
- **I10 (AC20).** A cancelled invite can never be accepted. Only the owner can cancel, and only pending invites.
- **I11.** Data the device holds for a user is never shown to a **different signed-in user**, nor after that user logs out. That data is:
  - the SW caches `pages`, `inertia`, `photos`;
  - `useLastSpot`;
  - Inertia history state.

  Guaranteed by:
  - clearing on logout (plus `clearHistory` under history encryption);
  - clearing on user change;
  - clearing on the login page, keeping only the pointer user's own unsaved capture.

  After a session **expires** without logout, the device may still show that user's last spot on `/offline` until `/login` is next opened. Accepted: it is the device owner's own data [F83].

**Acceptance criteria added at the design gate:**
- **AC19.** *Withdrawn in rev 4* (installed-app handoff, not built).
- **AC20.** An owner can cancel a pending invite; its link then returns 404 and cannot be accepted. A non-owner member cannot cancel (403). Cancelling an accepted or expired invite is refused, with no change.
- **AC21.** With the `tunnel` profile, the app is reachable over HTTPS at the configured domain. On a real iPhone with the app installed to the home screen, Google sign-in completes and leaves the installed app signed in. Geolocation and install also work, on both iPhone and Android. Manual and **gate-before**.
- **AC22.** In an installed production build with the network off, opening the app shows the last spot the user saw on that device: coordinates, accuracy, note and the directions link, with "Map unavailable offline". Manual.
  - No offline map, because of the OSM tile policy [F88].
  - The directions link opens Google Maps, which works offline only if the user has offline maps there.

## Failure Modes

### Inbound

- **FM1.** Two members park at once.
  - The household row lock serializes them. The second retrieves the first's spot and inserts its own.
  - Backstop: the partial unique index returns 500 rather than allowing two active spots. → I2.
- **FM2.** The photo is stored but the DB transaction fails.
  - The file is deleted before rethrow, and the user can retry. → I6.
  - If the worker is killed between storing the file and committing, the file is orphaned. It is unreachable, because no row references it. Accepted, with no garbage collection in this task [F107].
- **FM3.** Photo storage fails.
  - A `false` return throws before any DB write. No spot is created.
- **FM4.** Google reports an unverified email, no email, or no `email_verified` key.
  - Login is refused with a flash and no user is created. → I8, I5.
- **FM5.** The OAuth round trip fails: the user cancels, state is invalid or expired, Google returns an error, or the call times out after 10 s.
  - Redirect to `/login` with an error flash. Never a 500.
- **FM6.** Invite mail fails or times out after 5 s.
  - The invite is committed and a warning flash is shown. The link stays copyable.
- **FM7.** An invite is double-submitted, or accepted concurrently.
  - The invite row lock lets the first win. The second gets "already used", unless it is the same user, who is redirected.
  - The unique pivot pair prevents a duplicate membership. → I5.
- **FM8.** The user is removed from, or leaves, their current household.
  - Their `current_household_id` is cleared only when it matches, and the next request self-heals to the owned household. Old spot URLs return 404.
  - **Visible:** the switcher and Home show the owned household.
  - Device caches may still show already-seen data on a slow network, always under the dated banner. → I3.
- **FM9.** Logout, session expiry, or another person on the same device.
  - Caches and `useLastSpot` are cleared before logout, on a user-id change, and on any no-user page. A different person must reach an authenticated page through `/login`, which clears them first. → I11.
- **FM10.** Geolocation is denied, unavailable or times out.
  - No request is sent. A message specific to the code is shown, with Retry.
- **FM11.** Accuracy is worse than 50 m.
  - Parking is still allowed. A warning is shown, along with "±N m" and the accuracy circle.
- **FM12.** OSM tiles are unavailable.
  - A grey map is shown, with the marker and circle. Coordinates, note and directions still work. The photo is shown online or from the `photos` cache.
- **FM13.** A deploy lands while an old SW is active.
  - The new SW waits and a toast offers Reload. Old chunks stay available from `assets`. An Inertia 409 triggers a full reload online.
- **FM14.** The photo is too large or in an unsupported format.
  - The client downscales it, or falls back to the original. The server returns a field error and the spot is not saved. Production body limits are covered in the README.
- **FM15.** An invite is sent to an existing member, or an old invite is opened by a member.
  - Sending is refused. Accepting marks the invite accepted without creating a duplicate membership.
- **FM16.** An email matches a user whose `google_id` is a different non-null value.
  - Login is refused with a flash and no `google_id` is overwritten. → I8.
- **FM17.** Two first-login callbacks run in parallel.
  - The unique violation is caught outside the transaction and the lookup is retried once. Both requests end up logged in as the same user.
- **FM18.** A member is removed while their spot store is in flight.
  - The store authorizes the submitted `household_id` again after taking the household lock that removal also takes.
  - The request fails with 403 and the photo is deleted. The spot is never silently re-homed. → I1, I3.
- **FM19–FM22.** *Withdrawn in rev 4* (handoff).
- **FM23.** An invite is cancelled while the invitee is accepting it.
  - Accept locks and re-reads the invite. A deleted row returns 404, and no membership is granted. → I10.
- **FM24.** Cold launch on a weak signal after parking.
  - `/` may come from the `pages` cache, rendered before the park. Home then shows the dated banner, and leads with the device-local last spot, which is newer than the page.
  - If the network is fully down and nothing is cached, the offline page shows that last spot. → AC22.
- **FM25.** A spot page is reloaded offline when it was only ever visited by Inertia XHR, so it has no `pages` entry.
  - The navigation falls back to `/offline`, which shows the device-local last spot. Never a browser error page.
- **FM26.** A mutation's redirect-follow GET, such as POST `/spots` → GET `/spots/{id}`, might be routed through the SW on a slow network. Reviewers disagree on whether the spec routes it; this is unverified [F68].
  - **If it is routed:**
    - It is a cache miss (a new URL), so Inertia reports a network error. The sheet shows "Not saved — Retry".
    - The captured spot is already on the device as `unsaved` (§7 safety net).
    - Retry re-POSTs. Because of FM1, a duplicate POST just replaces the first spot with an identical one; the first is marked retrieved.
  - **If it is not routed:** the response arrives normally.
  - **Either way:** no data loss, and nothing wrong is displayed. Manual acceptance check.
- **FM27.** The session expires (or a 419 occurs) during "I parked here".
  - The response is a redirect to `/login`, or a 419. The unsaved capture survives the login page.
  - After sign-in, Home offers "Save now / Discard". Only the photo is lost.
- **FM28.** Accept and cancel run concurrently on the same invite.
  - The global lock order (household, then invite) serializes them, with no deadlock. Whichever wins, the other sees the final state: 404, or "already accepted". → I10.
- **FM29.** A shared device has the previous Google account still signed in.
  - `prompt=select_account` forces Google's chooser, so the next person picks their own account. The app sees a different user id and clears the previous user's device data. → I11.

### Outbound

What this design writes, and who reads it:

- **`users.current_household_id`.**
  - Read by `currentHousehold()` only (I3). Spot store ignores it.
  - Removal and leave clear it only when it matches the household.
  - Both fallbacks persist the healed value.
- **`users.email` and `users.google_id`.**
  - Read by invite acceptance and exposure, and by the OAuth lookup.
  - Written only by the OAuth callback.
- **`parking_spots.retrieved_at`.**
  - Read by the partial index, the Home active query and "Got the car".
  - Null means active.
- **`parking_spots.photo_path`.**
  - Read by the photo route and `has_photo`.
  - FM2/FM3 ordering guarantees the file exists when the path is set. The route still returns 404 on a missing file.
- **`parking_spots.lat` / `lng`.**
  - Read by `directions_url`, the Leaflet props and `useLastSpot`.
  - Cast with `decimal:7`.
- **`household_user`.**
  - Read by the policies, the switcher, the member list and `currentHousehold()`.
  - The owner is never removed.
- **`household_invites`.**
  - Read by accept, the pending list and cancel.
  - One `pending()` scope defines which invites are pending.
  - Cancel deletes the row.
- **Shared Inertia props.**
  - `auth.user` is an explicit array.
  - `currentHousehold` and `households` are lazy.
  - `pendingInvites` is passed to the owner only.
- **Device storage.**
  - The SW caches and `useLastSpot` are read offline.
  - They are cleared per FM9 (I11).
  - Partial reloads are never cached.
- **Sessions table.**
  - Removed members keep their session, but they lose access through the policies on their next request.

## Alternatives Considered

- **Plain `laravel/laravel` + manual Inertia/Vue setup.**
  - Rejected: it loses the kit's UI primitives, layouts, Wayfinder and Inertia 3 setup (research §Frontend).
- **Keep Fortify with every feature off and `Fortify::ignoreRoutes()`.**
  - Viable, but nothing of it would be used. Removing it is less code.
- **Packagist-tagged kit v1.0.2.**
  - Rejected: Laravel 12, Inertia 2 beta, Tailwind 3. The user approved Laravel 13 [F51].
- **SW at `/build/sw.js` with a `Service-Worker-Allowed` header.**
  - Rejected: `artisan serve` serves static files without middleware (Dockerfile:16).
- **Emitting the SW and manifest into the `public/` root.**
  - Rejected: generated files would sit outside the git-ignored build dir.
- **Pivot `role` instead of `owner_id`.**
  - Replaced: a non-null FK enforces exactly one owner. Same observable behaviour. This deviates from the requirements' wording.
- **Remember-me with an overridden `getAuthPassword()`.**
  - Rejected: it fakes a credential. A 30-day session does the same job.
- **Installed-app login handoff (rev 3; code bound to the initiating session, completed by the callback, claimed by polling).**
  - **Withdrawn [F39].** Any guest can mint a code and phish a victim into completing it. The attacker's polling session is then logged in as the victim.
  - It also blocks the popup after an `await` [F63], shares a throttle counter with the callback, and breaks under `guest` when cookies are shared.
  - A safe variant (a sign-in code the user types into the app) is deferred until the real-iPhone check (AC21) shows the plain redirect fails.
- **Client-generated handoff code (agy's suggestion).**
  - Rejected: it has the same takeover direction.
- **Laragon PHP on the host for the app.**
  - Rejected: the user chose Docker. Host PHP is used only for bootstrap.
- **Queued invite mail.**
  - Rejected: it would need a worker for a single email.
- **Hashed invite tokens.**
  - Not taken: a token grants nothing without the matching verified Google email.

## Key Decisions

- **KD1.** Edit the kit's migrations rather than adding new ones (greenfield). Migrations are forward-only from the first deploy.
- **KD2.** `households.owner_id` instead of a pivot role.
- **KD3.** Denials:
  - spots and photos: 404 (`denyAsNotFound`);
  - owner-only actions attempted by a member: 403;
  - non-member household actions: 403.
- **KD4.** 30-day session as the config default. No remember-me.
- **KD5.** The SW is served at `/sw.js` outside the `web` group and registered manually with `workbox-window`. PWA in production builds only, and dev unregisters it.
- **KD6.** All tooling runs in the `laravel.test` container, except the one-time host bootstrap.
- **KD7.** `directions_url` is built server-side from the `decimal:7` cast values with `%2C`.
- **KD8.** The kit's frontend gate (`vp check`, `vue-tsc`) is kept, alongside pint and phpstan level 7.
- **KD9.** Every throttle uses a named `RateLimiter`.
- **KD10.** Spot creation authorizes the submitted `household_id`, never the current-household pointer.
- **KD11.** Global lock order: the household row, then the invite row.
- **KD12.** SSR is disabled.
- **KD13.** The SW is a hand-written `injectManifest` worker.

## Risks

- **R1. Vite+ type-aware lint.** It may object to Leaflet or workbox-window types. Fix with proper type packages, and extend the ignore list only for generated code.
- **R2. Bind-mount performance** on `C:\`. `node_modules` lives in a named volume [F99]. Use polling watch if needed.
  - Sail recommends the WSL2 filesystem. That is a documented alternative if performance is poor; the project stays where the user put it.
- **R3. Port 80** is free now, but Laragon's Apache uses it when started. `APP_PORT` is configurable; the Google redirect URI must match.
- **R4. Real-device testing** goes through the optional tunnel (§9, AC21). Production HTTPS hosting is out of scope.
- **R5. Map rendering and geolocation** can't be tested in Pest. Covered by prop assertions and manual acceptance.
- **R6. OSM tile policy.** Household-scale use, with attribution and no prefetch.
- **R7. SQLite in tests vs PostgreSQL in Docker.** `lockForUpdate` and FK ordering behave differently. Mitigated by `migrate:fresh` on pgsql. FM1/FM18/FM23 lock ordering is checked manually.
- **R8. iOS installed-app cookies** [F15]. Verified by AC21 at a gate-before checkpoint.
  - The outline schedules that checkpoint **early**: right after the scaffold, Socialite login and tunnel exist, with a minimal installable manifest. It does not wait for the full PWA [F108].
  - A failure creates a follow-up task (safe handoff). This task does not ship a workaround.
- **R9. ngrok free tier** (interstitial page, account required). The tunnel is optional dev tooling.
- **R10. Client clock drift** of more than 2 minutes can mis-show or hide the saved-copy banner. Accepted.
- **R12. `injectManifest` under Vite+ is unproven.** The spike exercised `generateSW` only. It is checked at the start of the PWA phase, with the fallback stated in §8.
- **R13. Stored email can lag Google.** A reassigned or changed Google email is picked up only at the next login. Sessions slide for 30 days. Accepted for a household app.
  - Gmail dot and plus variants must match the invite exactly. The mismatch page says which account to try.
- **R11. Photo EXIF.** When the canvas downscale fails, the uploaded original keeps its EXIF GPS data. Accepted: it is visible only to household members, who already see the spot's coordinates [F62].
