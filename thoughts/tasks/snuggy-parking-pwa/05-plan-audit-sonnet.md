# Audit of `05-plan.md`: Snuggy Parking PWA

## Summary

The repo holds only `.gitignore` and `README.md`, so there was no plan-target code to read. I checked the plan against the real pinned kit instead. I ran `composer create-project laravel/vue-starter-kit "dev-main#c290133…"` in a scratch directory outside the repo; the `#sha` form works. I also installed Socialite, Pest and `@inertiajs/core` 3.9.1, and read `vite-plugin-pwa@2.0.0`. I ran four things there:

- the plan's chisel command
- the plan's migrations on SQLite
- a small Eloquent probe
- PHPStan with Larastan on the plan's relation signatures

The repo is untouched (`git status` shows only `?? thoughts/`). I removed my scratch directories. `/tmp/audit-kit/` is not mine; I left it.

The plan has **3 blocking defects**, **9 serious ones**, and several smaller ones. All are fixable in the plan.

| # | Severity | Finding |
|---|---|---|
| 1 | Blocking | Plain `docker compose build` leaves `WWWGROUP` unset, so the Sail image fails to build. |
| 2 | Blocking | The base `Controller` has no `authorize()`. |
| 3 | Blocking | `sail:install` rewrites `phpunit.xml`, so tests run on pgsql. The plan, the design and CI assume SQLite. |
| 4 | Serious | `retrieve` is a silent no-op: `retrieved_at` is not fillable. |
| 5 | Serious | The logout and invite "different account" forms have no source for a CSRF token. |
| 6 | Serious | A fresh Home overwrites the `unsaved` capture whenever an older active spot exists. |
| 7 | Serious | A session-expired POST gives 419, not a redirect to login. The plan's failure handlers can't fire for it. |
| 8 | Serious | `HouseholdMemberController@destroy` is a membership oracle for outsiders (I1). |
| 9 | Serious | The FM18 negative control cannot fail. |
| 10 | Serious | FM17 and FM29 tests are infeasible as written. |
| 11 | Serious | CI breaks, and the dev DB goes stale after the migrations are edited. |
| 12 | Serious | The P4 switcher and nav link are not rendered until P5. |

---

## Reachability Issues

1. **P4 UI lands where it is never mounted (plan 600–603, 756).**
   - P4 puts `HouseholdSwitcher` and the "Household" link in `AppHeader`.
   - In the kit, `layouts/AppLayout.vue` wraps `AppSidebarLayout`. The switch to `AppHeaderLayout` happens only in P5 (line 756).
   - So P4's manual check ("see the household") and the AC8 switcher have no visible entry point until P5.
   - Fix: move the layout switch into P4 or P2.

2. **`UnsavedSpotPrompt` is unreachable in the common case (plan 862–864, 871).**
   - FM27 relies on the `unsaved` entry surviving until the prompt shows.
   - Home does `if (activeSpot) writeLastSpot(... 'saved')` with no `unsaved` guard. Only the `else` branch (`markRetrieved`) is guarded.
   - Failed park POST, then re-login, then Home: the household still has its previous active spot, so Home overwrites the `unsaved` capture and the prompt never appears.
   - This is the usual case when someone forgot "Got the car".
   - Fix: never overwrite `unsaved` from Home or Show, and render the prompt before any write.

3. **The ParkSheet "Not saved — Retry" handlers cannot fire for the cases they exist for (plan 868, 810).**
   - Inertia's `onError` runs only when `props.errors` is non-empty, which means validation (`@inertiajs/core dist/index.js:2706–2710`).
   - HTTP failures go through `onHttpException` or the `httpException` event (`index.js:2692`, `2749`).
   - A non-Inertia HTML 419 or 500 shows `dialog.show(...)`, a modal, not a reload (`index.js:2749–2755`).
   - The plan's "419 is handled by the kit's default (a page reload)" is wrong.
   - The sheet therefore stays on "Saving…" until the 20 s timer.

4. **"A session-expired POST already redirects to login, via `auth`" is wrong (plan 810).**
   - `VerifyCsrfToken` is in the `web` group and runs before the route's `auth`. An expired session gives 419.
   - The same applies to a native logout POST from an expired session: the user lands on Laravel's HTML 419 page.

5. **The P3 test-mode branch of `UserMenuContent`'s `Link` is not the reachable one.** The kit's logout is an Inertia `<Link … as="button">`, and P6 replaces it. P2 meanwhile keeps `route('logout')` working only through Wayfinder's generated `logout`. This is fine, but the P6 replacement needs the token fix in Other #5.

6. **FM17 retry path is unreachable in the described test (plan 318).**
   - The competitor row is inserted by a `creating` listener inside `DB::transaction`. That is the same connection and the same savepoint.
   - The unique violation rolls the savepoint back and takes the competitor with it.
   - The retry then finds nobody and creates the user normally, so the asserted "logs in the existing user" cannot happen.
   - A truly separate connection is impossible in one test process.
   - The fallback, "seeded conflict", hits `resolveUser` step 2 and never raises `UniqueConstraintViolationException`.
   - Neither variant exercises the catch-and-retry.

7. **FM29 test (plan 317).**
   - The first option, "assert on `Socialite::fake` redirect target", is impossible: `FakeProvider::redirect()` returns the constant `https://socialite.fake/google/authorize`.
   - `with()` is forwarded to the real provider through `__call`, so the parameters never reach the returned URL.
   - The second option, reading `parameters`, works only by reflection on `Socialite::driver('google')->provider()`.
   - A cleaner test skips the fake and asserts `prompt=select_account` in the `Location` of the real provider, which makes no network call.

---

## Shape Mismatches

1. **`ParkingSpot` mass assignment (plan 664 vs 715).**
   - `$fillable` omits `retrieved_at` and `household_id`.
   - `retrieve` does `$spot->update(['retrieved_at' => now()])`.
   - I reproduced this: `retrieved_at` stays `NULL`, silently, because the kit does not enable `preventSilentlyDiscardingAttributes`.
   - AC5 fails. `RetrieveSpotTest` would catch it, but the plan's code is wrong.
   - Fix: `forceFill`, or an explicit query update.
   - There are also no `ParkingSpot` or `HouseholdInvite` factories, and tests must work around the fillable list.

2. **`auth.user` is nullable in PHP and non-null in TS (plan 296 vs 168).**
   - `share()` returns `$request->user()?->only(...)`, which is `null` on `auth/Login`.
   - P2's `types/auth.ts` rewrite keeps `Auth = { user: User }`.
   - The planned `syncDeviceUser(currentUserId: number | null …)` caller will read `page.props.auth.user?.id`, which the typeAware lint (`denyWarnings: true`) flags as an unnecessary optional chain, or the code ignores the null case.
   - Fix: `user: User | null`.

3. **The `households` shared prop carries `pivot` (plan 298).**
   - `->get(['households.id','households.name'])` on a `BelongsToMany` appends pivot columns.
   - I reproduced this: `[{"id":1,"name":"h","pivot":{"user_id":1,"household_id":1,"created_at":…}}]`.
   - The TS type is `{id; name}[]`.
   - Use `->toBase()->select(...)`, or map explicitly.

4. **`generated_at` is not in the shared-props TS type (plan 802 and 878).**
   - P2 edits `global.d.ts` for `currentHousehold` and `households`. P6 never adds `generated_at`.
   - It falls through to `[key: string]: unknown`, so `Date.parse(unknown)` fails `types:check`.

5. **`LastSpot` vs the write in `Spots/Show` (plan 834–836 vs 861).**
   - `writeLastSpot(user.id, {...spot, status})` leaves out the required `household_name` and `saved_at`.
   - It also spreads extra keys (`has_photo`, `photo_url`, `is_active`, `retrieved_at`) into `localStorage`.
   - `saved_at` is what the banner card and the prompt compare against `generated_at` (plan 871, 880). If it is `undefined`, those comparisons fail.

6. **`LastSpot` has no household id (plan 864).**
   - `markRetrieved` is gated on "the stored spot belongs to `household.name`".
   - Two households with the same name are plausible, since the personal household is "{first word}'s household" and users get invited into others'.
   - A Home for household B with no active spot then marks household A's saved spot `retrieved`.
   - Add `household_id`.

7. **Flash shape (plan 270).**
   - The plan gives `['type' => 'error', 'message' => …]`.
   - The kit listens on `flash.toast` and flashes with `Inertia::flash('toast', [...])` (`ProfileController::update`, `lib/flashToast.ts`).
   - The flash must be keyed `toast`. P4's flashes are unspecified.

8. **Client `directions_url` for unsaved entries (plan 837).** The server format uses `decimal:7` strings (`51.5000000%2C…`). "Same format" on a JS number needs `toFixed(7)`. Say so, or the offline link differs from the saved one.

9. **`npm install` does not "re-sync" a lockfile (plan 104).** The kit ships no `package-lock.json`, only `composer.lock`, `pnpm-workspace.yaml` and `.npmrc` (`ignore-scripts=true`). The first install creates a floating lock. Harmless, but the claim is false.

---

## Dead Branches

- **`HouseholdPolicy::view` (plan 500) and `::parkIn` (plan 504, "consumed in P5") are never called.**
  - `HouseholdController@show` uses `currentHousehold()` and no `authorize`.
  - P5 `store` uses `isMemberOf` directly (plan 703, 711).
  - Design §5 says `parkIn` is authorized; the plan's `authorize()` returns `true`.
  - Either call them or drop them. Today they are untested policy code.
- **`alreadyMember` prop (plan 558)** is computed but no `Invites/Show` state (plan 596–599) uses it.
- **Socialite `guzzle` fallback (plan 248).** The passthrough exists: `SocialiteManager.php:253` passes `Arr::get($config, 'guzzle', [])`. The `setHttpClient` branch is dead.
- **P2 deletions chisel already did.** I ran it: the passkey well-known route, the 2FA and passkey migrations, `chisel*.php` and `InstallFeaturesCommand` were all gone after the P1 command. P2's "if chisel left them" steps are no-ops, except `TwoFactorAuthenticationRequest.php`, which remains and is not named.
- **`app.ts` `case name === 'Welcome'`** stays after `Welcome.vue` is deleted. The plan never edits it.
- **`Household::findOrFail` (plan 531)** after `exists:households,id` can never 404.

---

## Failure Modes Not Ruled Out

**Checked:** FM1–FM18, FM23–FM29. FM19–22 are withdrawn. Invariants checked: I1–I8, I10, I11 (I9 withdrawn). Coverage: all 46 active ids are claimed in the "Satisfies" lines. I verified the union of plan headers equals AC1–22 minus 19, and FM1–29 minus 19–22.

| Id | Verdict |
|---|---|
| FM1, FM2, FM3, FM4, FM5, FM6, FM7, FM8, FM10, FM11, FM12, FM13, FM14, FM15, FM16, FM23, FM25, FM26 | Mitigation present and plausible. |
| **FM9 / I11** | **Broken as specified.** The logout form needs a `_token`. The plan says "from `usePage().props` or a meta tag" (line 858), but neither exists. The kit shares no CSRF prop and `app.blade.php` has no `csrf-token` meta. The XSRF cookie is encrypted and unusable as `_token`. The same applies to the `Invites/Show` "different Google account" form (line 598), which also hard-codes `/logout`. Logout 419s and device data is already cleared. |
| **FM27** | **Not ruled out.** See Reachability #2, #3, #4. |
| **FM17** | The test cannot prove the catch-and-retry (Reachability #6). |
| **FM18 / I3** | **Negative control cannot fail (plan 703, 772).** `store` has an early `abort_unless(isMemberOf, 403)` before the lock. Removing only the under-lock check leaves the non-member test passing, so the plan's own "prove the load-bearing test fails" rule is not met. Test the under-lock branch directly (for example, remove membership between the early check and the lock), or drop the early check. |
| **FM28 / R7** | See Other #3. The `DB::listen` "first `for update`" test cannot work on SQLite (I confirmed `lockForUpdate()` emits `select * … limit 1` with no lock clause), yet line 618 says it is for SQLite. It works only if the suite runs on pgsql. |
| **FM29** | `prompt` test infeasible as written (Reachability #7). |
| **FM24** | Manual only; the "Last spot you saw" card depends on `saved_at` (Shape #5). |
| **I1** | **Oracle (plan 572–573).** `abort_unless(target is member, 404)` runs before `authorize('removeMember')`. Any authenticated user can `DELETE /households/{any}/members/{any}` and read membership from 404 vs 403, in households they don't belong to. No `scopeBindings` on `{user}`. Authorize first, then check membership. Same shape, weaker, on `{invite}` binding (404 before 403). The `exists:` rules on `household_id` (plan 531, 686) similarly separate "no such household" (422) from "not yours" (403). |
| **I7** | `currentHousehold()` step 3 is reachable only for rows created outside the action. Fine as a self-heal, but the plan gives no reachability note. The test must delete household rows by hand. |
| No limiter test | Design §10 and the Behavior Contract require "OAuth limiter: the 21st hit returns 429". P2's test table has none. Only the `invites` limiter has a 429 test (line 611). |

---

## Other

1. **Blocking: P1 container build fails on a plain `docker compose` (plan 99).**
   - Sail's `compose.yaml` passes `WWWGROUP: '${WWWGROUP}'` as a build arg, and the Dockerfile runs `RUN groupadd --force -g $WWWGROUP sail`.
   - Only the `sail` bash script exports `WWWUSER`/`WWWGROUP` (`vendor/laravel/sail/bin/sail:150–151`).
   - The plan never sets them in `.env` and doesn't pass `--devcontainer`, which would append them.
   - With them blank the build fails.
   - Related: `docker compose exec` without `-u sail` runs as root while supervisor serves as `sail`.

2. **Blocking: base `Controller` has no `authorize()`.**
   - The kit's `app/Http/Controllers/Controller.php` is an empty abstract class with no `AuthorizesRequests`.
   - `$this->authorize(...)` is used at plan 531, 541, 573, 581, 714, 715 and 717, and the plan never adds the trait.
   - Every policy-backed route would 500 with `BadMethodCallException`.

3. **Blocking: `sail:install` rewrites `phpunit.xml`.**
   - `configurePhpUnit` deletes the `DB_CONNECTION` line and sets `DB_DATABASE=testing`, so tests run against pgsql in the container.
   - P1 never mentions `phpunit.xml`, and the design says tests run on in-memory SQLite.
   - Consequences:
     - **CI breaks.** `.env` is copied from `.env.example`, which now says `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, and CI has no pgsql.
     - **`composer setup` breaks.** It runs `migrate --force` against `pgsql` (plan 115 "leave other steps"; design §1 says "Adapt `setup` to the container", which the plan omits).
     - The FM28 test's SQLite claim is wrong.
   - Decide the DB for tests and write it into P1.

4. **Dev DB goes stale.**
   - P1 migrates the kit's `users` table (with `password NOT NULL`). P2 then edits that migration in place (KD1).
   - Nothing before P7 runs `migrate:fresh` (plan 958). Tests are unaffected, but P2's real-Google manual check and every later manual check run against a schema without `google_id`.
   - Add `migrate:fresh` to P2.

5. **`vite.config.ts` (plan 96).** The kit already has `server: { watch: { ignored: [...] } }`. "Add `server: {…}` beside `plugins`" creates a duplicate key; it has to be merged.

6. **PHPStan zero-errors is not achievable with the plan's signatures.**
   - I ran level 7 with Larastan on the plan's `owner(): BelongsTo` and `members(): BelongsToMany`.
   - It reports `missingType.generics` for each, and `property.notFound` for `$owner_id`.
   - The plan needs `@return` generics and `@property` docblocks for `google_id`, `avatar`, `current_household_id` and the new models.

7. **`sw.ts` and TypeScript (plan 399–403).**
   - `tsconfig.json` includes all `resources/js/**/*.ts` with `lib: [ESNext, DOM, DOM.Iterable]`.
   - `/// <reference lib="webworker" />` merges into the app program for every file.
   - `skipLibCheck` hides duplicate-declaration errors, but `self` and other global types change for the whole app.
   - Plan a separate `tsconfig` or a module-scoped `declare const self`.

8. **`Inertia::clearHistory()` ordering (plan 289, 801).**
   - It writes `session([CLEAR_HISTORY => true])` (`ResponseFactory.php:189–192`).
   - If it is called before `session()->invalidate()` it is flushed. The plan doesn't fix the order.
   - `config/inertia.php` in the kit has no `history` key. The key is `history.encrypt` (vendor config), so the "verify the key name" hedge can be resolved now.

9. **Smaller items:**
   - `docker compose ps … healthy` (plan 119): only `pgsql` defines a healthcheck.
   - Sail also publishes host ports 5432/1025/8025, which can collide with Laragon.
   - The plan relies on `@` aliasing in the SW build. This works because `laravel-vite-plugin` sets it and `vite-plugin-pwa` forwards `resolve`/`define`, which I confirmed for `define` in `vite-build-*.js`.
   - The `User` model already uses `#[Hidden]`. `$hidden` merges with it, so no conflict, but the styles mix.

**Verified fine (no finding):**
- The pinned `#sha` form resolves.
- `PHP_CLI_SERVER_WORKERS` is honoured because Sail sets `LARAVEL_SAIL=1` (`ServeCommand.php:112–122`).
- `TrustProxies` reads `config('trustedproxy.proxies')` and is a global middleware.
- The plan's migrations run, and roll back, on SQLite.
- Pest `^4.7` and `pest-plugin-laravel ^4.1` resolve against the kit.
- `Inertia::always`, `clearHistory` and `history.encrypt` exist.
- Socialite's `guzzle` passthrough and a relative `redirect` both work.
- `X-Inertia` and `X-Inertia-Partial-Component` match the SW matcher.
- `buildBase` is a real plugin option.
- The `mail.timeout` hardcoded `null` is as stated.
- `filesystems.local.serve` is `true` by default.
