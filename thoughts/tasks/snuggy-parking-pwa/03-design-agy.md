# Critique of `03-design.md` (Revision 4)

This review evaluates **Revision 4** of [03-design.md](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md) in the context of [01-questions.md](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/01-questions.md) and [02-research.md](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/02-research.md). Code claims and `file:line` citations are verified against the reference starter-kit scaffold in the session scratchpad (`ref/`).

---

## Summary

Revision 4 resolves the rev-3 login handoff vulnerabilities by stripping the handoff entirely, keeps the tunnel for real-device OAuth verification, and introduces a client-side device-local spot fallback (`useLastSpot`) with a precached `/offline` page.

However, a skeptical examination reveals critical operational, runtime, and architectural defects:

1. **Unworkable Offline Page Build:** The design introduces a separate entry point `resources/js/offline.ts` for `/offline`, but omits adding it to `laravel.input` in `vite.config.ts`. In production builds, `offline.blade.php` will fail with an unhandled `ViteManifestException` when attempting to resolve this unbundled asset.
2. **Broken Container Environment from Stripped PHP Flags:** The design overrides `SUPERVISOR_PHP_COMMAND` without `/usr/bin/php -d variables_order=EGPCS`. Under Ubuntu's default PHP CLI, `$_ENV` will be empty, causing `artisan serve` child workers to drop all environment variables passed to Docker.
3. **PWA Runtime Interception Aborting OAuth & Logins:** Workbox `NetworkFirst` (3s timeout) is configured for all navigations without excluding `/auth/google/*` or `/login`. Slow OAuth token callbacks (>3s) will hit a cache miss and trigger `handlerDidError`, serving `/offline` and aborting user sign-in.
4. **Self-Destructive Cache Clearance on `/offline`:** The rule clearing user data on "any no-user page" directly contradicts the offline page architecture. Because `GET /offline` has no session or user data, loading `/offline` would wipe the exact `useLastSpot` data it is meant to display.
5. **Starter-Kit Removal Collateral Damage:** The instruction to remove lines 17–31 of `routes/settings.php` accidentally deletes `settings/appearance` (which is kept by contract), while deleting `DeleteUser.vue` and `SecurityController` without updating `Profile.vue` and `Layout.vue` will cause frontend builds and type-checking to fail.

---

## Approach Concerns

### 1. `resources/js/offline.ts` Missing from Vite Inputs Breaks Build
- **Citation:** [03-design.md:221](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L221), [03-design.md:281](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L281), `ref/vite.config.ts:13`
- **Problem:** Section 7 states: *"GET /offline is a static Blade view: no session, no data. It has its own small Vite entry, `resources/js/offline.ts`"*. However, `ref/vite.config.ts:13` defines:
  ```ts
  laravel({
      input: ['resources/css/app.css', 'resources/js/app.ts'],
      refresh: true,
  })
  ```
  Section 9 only configures the `server` block in `vite.config.ts` and never adds `'resources/js/offline.ts'` to `input`. Consequently, Vite will not compile `offline.ts` during `npm run build` or generate an entry in `public/build/manifest.json`.
- **Impact:** When Workbox precaches `/offline` or a user loads it, `offline.blade.php` rendering `@vite(['resources/css/app.css', 'resources/js/offline.ts'])` will throw `ViteManifestException: Unable to locate file in Vite manifest: resources/js/offline.ts`. The offline page 500s.
- **Fix:** Explicitly specify updating `vite.config.ts` to include `'resources/js/offline.ts'` in `input`.

### 2. Overriding `SUPERVISOR_PHP_COMMAND` Strips `-d variables_order=EGPCS` and Clears `$_ENV`
- **Citation:** [03-design.md:278](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L278), `ref/vendor/laravel/sail/runtimes/8.4/Dockerfile:16`, `ref/vendor/laravel/framework/src/Illuminate/Foundation/Console/ServeCommand.php:195-201`
- **Problem:** Section 9 states: *"It overrides `SUPERVISOR_PHP_COMMAND` with `artisan serve --host=0.0.0.0 --port=80 --no-reload`"*.
  In the official Sail 8.4 Dockerfile line 16, the command is:
  ```dockerfile
  ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80"
  ```
  In Ubuntu PHP CLI, `php.ini` defaults `variables_order = "GPCS"`. The `-d variables_order=EGPCS` flag is strictly required to populate PHP's `$_ENV` superglobal. `ServeCommand.php:195` starts worker processes by passing `(new Collection($_ENV))`:
  ```php
  $process = new Process($this->serverCommand(), public_path(), (new Collection($_ENV))->mapWithKeys(...)->all());
  ```
- **Impact:** Stripping `/usr/bin/php -d variables_order=EGPCS` leaves `$_ENV` empty. Worker processes spawned by `artisan serve` will receive no environment variables from Docker or compose. All container configuration (`DB_CONNECTION=pgsql`, `APP_KEY`, etc.) disappears from runtime workers.
- **Fix:** Keep the full binary invocation: `SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80 --no-reload"`. (Additionally, note that in `ServeCommand.php:119`, `LARAVEL_SAIL=1` already allows `PHP_CLI_SERVER_WORKERS > 1` without `--no-reload`).

### 3. Blanket Navigation Rule in Service Worker Intercepts OAuth Callbacks
- **Citation:** [03-design.md:258-264](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L258-L264)
- **Problem:** The runtime caching table specifies:
  `Navigations | NetworkFirst, networkTimeoutSeconds: 3 | pages | 30`
  with:
  *"On network failure the response comes from `pages`; on a cache miss, the precached `/offline` is served via a `handlerDidError` plugin"*.
  Workbox navigation routes match any `request.mode === 'navigate'` inside scope `/`. This includes `GET /auth/google/redirect` and `GET /auth/google/callback?code=...&state=...`.
- **Impact:** Exchanging an authorization code with Google and establishing a session over slow mobile networks or dev proxies frequently exceeds 3 seconds. When it does, `NetworkFirst` triggers a 3-second timeout, checks `pages` (cache miss for the unique OAuth callback URL), invokes `handlerDidError`, and returns the static `/offline` HTML page! The OAuth flow aborts, the auth code is lost, and the user is left on the offline screen.
- **Fix:** Explicitly blacklist `/auth/*` from the navigation route regex/callback or route them via `NetworkOnly` with no timeout.

---

## Invariants At Risk

### 1. Invariant I11 & FM9 at Risk — Blanket "Clear on any no-user page" Wipes Data on `/offline`
- **Citation:** [03-design.md:221](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L221), [03-design.md:271](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L271), [03-design.md:368](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L368), [03-design.md:401](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L401)
- **Invariant:** *I11: Data the device holds for a user — SW caches `pages`, `inertia`, `photos`, and `useLastSpot` — is never shown under another user's session. It is cleared on logout, on user change, and on any no-user page.*
- **Risk:** Section 7 and Section 8 define `GET /offline` as *"a static Blade view: no session, no data"*. When a user loses signal and hits `/offline`, the page has no authenticated user (`auth.user` is undefined/null). If the client-side cleanup runs on "any no-user page" as required by I11 and FM9, `/offline` will execute the cache and `localStorage` clear, wiping `useLastSpot` before `offline.ts` can render it.
- **Impact:** AC22 ("opening the app shows the last spot the user saw on that device") fails.
- **Fix:** Clarify that cache clearance on unauthenticated views applies strictly to `/login` (the entry point for guest-to-auth transitions), explicitly exempting `/offline`.

### 2. Invariant I3 & I7 at Risk — Ambiguous Multi-Household Fallback in Seeders and Tests
- **Citation:** [03-design.md:108](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L108), [03-design.md:113](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L113), [03-design.md:353](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L353)
- **Invariant:** *I3 (AC17): Every use of "current household" resolves to a household the user is currently a member of.*
- **Risk:** Section 4 step 2 defines the fallback: *"Otherwise falls back to the household the user owns (lowest id if several)"*.
  `UserFactory` is given an `afterCreating` hook that calls `CreatePersonalHousehold`. When a test creates a user and then creates a test household owned by that user (`Household::factory()->create(['owner_id' => $user->id])`), the user now owns two households: ID 1 (personal) and ID 2 (test-specific).
  If the test does not explicitly set `current_household_id = 2`, `currentHousehold()` will pick ID 1 (lowest ID). Any test asserting behavior on the newly created household will operate on the personal household instead, leading to test false-negatives or subtle state bleed.
- **Fix:** Specify that `CreatePersonalHousehold` sets `current_household_id` immediately, and tests creating secondary households should explicitly update `current_household_id` or use a factory state `withoutPersonalHousehold()`.

---

## Failure Modes Not Mitigated

### 1. Weak-Signal Form Submission Followed by Redirect Interception (FM26)
- **Citation:** [03-design.md:214-215](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L214-L215), [03-design.md:429-432](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L429-L432)
- **Gap:** In FM26, the design states: *"A mutation's redirect, such as POST `/spots` → GET `/spots/{id}`, is served from cache on a slow network. This should not happen: the SW routes only GETs, and fetch follows redirects of a non-intercepted POST at the network level. Unverified: it is a manual acceptance check"*.
- **Reality:** According to the W3C Service Worker Fetch specification, when the browser follows an HTTP 302 redirect from a POST request, the redirected `GET /spots/{id}` request **is** dispatched to the Service Worker fetch event.
  Because it carries `X-Inertia: true`, it matches the `inertia` runtime caching rule (`NetworkFirst, 3 s`).
  In an underground garage where latency is >3s:
  1. `NetworkFirst` times out after 3 seconds.
  2. Workbox queries the `inertia` cache for `/spots/{id}`.
  3. Spot `{id}` was just created; it is an absolute cache miss.
  4. Workbox returns a fetch failure to Inertia.
  5. Inertia triggers an error modal / failure state.
  6. Crucially, `useLastSpot` only saves data when `Spots/Show` or `Home` renders a fresh response ([line 215](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L215)). Because `Spots/Show` never loaded, `useLastSpot` was never updated!
- **Mitigation:** The parking form submission on the client must write the coordinates, note, and timestamp to `useLastSpot` optimistically or inside Inertia's `onBefore` / `onStart` hooks so the device retains the spot even if the follow-up redirect times out.

### 2. Missing User ID Discovery Mechanism on `/offline`
- **Citation:** [03-design.md:214](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L214), [03-design.md:221](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L221)
- **Gap:** Line 214 specifies: *"The composable `useLastSpot` writes to `localStorage` under a key per user id"*.
  Line 221 states: *"GET /offline is a static Blade view: no session, no data. It has its own small Vite entry, `resources/js/offline.ts`"*.
  Because `/offline` is completely static and unauthenticated, `offline.ts` has no server-provided user ID prop. The design never defines how `offline.ts` discovers which user ID's key to read in `localStorage`.
- **Mitigation:** Define a known pointer in `localStorage` (e.g. `snuggy_active_user_id`) updated whenever `auth.user.id` is present, or specify that `offline.ts` inspects `localStorage` for the most recent valid spot entry.

---

## Missed Behavior Changes

The following externally observable changes were not documented in the **Behavior Contract** ([lines 313–348](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L313-L348)):

1. **`settings/appearance` Route Group Relocation:**
   - In `ref/routes/settings.php:17-31`, `settings/appearance` was inside the `['auth', 'verified']` group.
   - Section 1 step 4 ([line 32](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L32)) states: *"Remove what chisel cannot: ... the settings Security/password routes and controller (routes/settings.php:17-31)"*. Line 30 is `appearance.edit`. If lines 17–31 are removed, `appearance.edit` is deleted.
   - While line 322 of the Behavior Contract notes that `settings/appearance` requires only `auth`, the contract fails to call out that the route is relocated to the unverified `['auth']` group (lines 10–15).
2. **Singular Scope of `GET /household`:**
   - In `01-questions.md:18`, a user can belong to multiple households.
   - The contract lists `/household` under "New routes". It does not specify that `/household` is parameterless and exclusively renders the user's `currentHousehold`. A member cannot view their other households' details or member lists without first switching their active household via `PUT /current-household`.
3. **Mismatched Invite Inspection Returns 200 with Masked View:**
   - [01-questions.md:24](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/01-questions.md#L24) requires that *"email-mismatched invites are refused with a clear message"*.
   - Section 4 ([line 118](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L118)) specifies that `GET /invites/{token}` renders a masked notice ("This invite was sent to a different email address") rather than returning 403 or redirecting. The contract only states: *"reveals household and inviter only to the invited address"*, omitting the HTTP 200 masked page behavior.
4. **Invalid Invite Revocation Redirects Back:**
   - Section 6 ([line 149](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L149)) specifies that attempting to `DELETE` an expired or already-accepted invite redirects back with an error flash and status 302/303. The contract only mentions that *"Owners can cancel pending invites"*.

---

## Other

### 1. Starter-Kit Frontend Breakages from Missing Cleanup Targets
- **`ref/resources/js/layouts/settings/Layout.vue:10, 19-22`:**
  `Layout.vue` imports `editSecurity` from `@/routes/security` and renders the "Security" navigation button. Removing the Security routes will cause Wayfinder to drop `@/routes/security`, breaking `npm run build` and `vue-tsc`. `Layout.vue` must be included in the cleanup list in Section 1 step 4.
- **`ref/resources/js/pages/settings/Profile.vue:8, 107`:**
  `Profile.vue` imports `DeleteUser` and renders `<DeleteUser />`. Removing `DeleteUser.vue` ([line 32](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L32)) without editing `Profile.vue` will cause Vue compilation and TypeScript checks to fail.
- **`ref/app/Models/User.php:32, 35`:**
  `User.php` implements `MustVerifyEmail, PasskeyUser` and uses `PasskeyAuthenticatable, TwoFactorAuthenticatable`. When Fortify is uninstalled, these classes no longer exist. `User.php` must be explicitly stripped of these traits and interfaces in Section 1 step 4.

### 2. Missing `workbox-window` in Explicit `package.json` Dependencies
- **Citation:** [03-design.md:242](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L242), `ref/package.json:13-31`
- **Observation:** `app.ts` imports `Workbox` from `workbox-window`. While `workbox-window` is a transitive dependency of `vite-plugin-pwa`, `package.json` in the starter kit does not list it in `dependencies`. For strict package managers and `vue-tsc` type-resolution, `workbox-window` should be explicitly added to `dependencies`.

### 3. PostgreSQL FK Drop Order on Migration Rollback
- **Citation:** [03-design.md:83-84](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L83-L84)
- **Observation:** `households.owner_id` references `users(id)`, while `users.current_household_id` references `households(id)`. In PostgreSQL, running `migrate:rollback` on the `households` migration will fail with a foreign key dependency error if `down()` attempts to drop table `households` before dropping column/constraint `current_household_id` from table `users`. The migration's `down()` method must explicitly drop the foreign key constraint from `users` first.
