## Summary

The repo holds only `README.md`, `.gitignore` and `thoughts/`, so "the actual codebase" here means the kit and packages the plan builds on. I cloned `laravel/vue-starter-kit` at the pinned `c290133` into a scratch dir (`%TEMP%\audit-kit\kit`) and installed its dependencies plus the plan's additions without running any package scripts. Resolved versions: framework 13.39.0, inertia-laravel 3.5.2, socialite 5.31.0, `@inertiajs/core` 3.9.1, vite-plugin-pwa 2.0.0, workbox 7.4.1. Unprefixed paths below point into that tree; `plan:N` is `05-plan.md`, `design:N` is `03-design.md`.

The plan has no `Reachable when:` annotations, so I traced every new branch myself.

The four that matter most:
1. **The device-local "last spot" gets overwritten by stale pages.** This breaks both FM24 and FM27, the core weak-signal promise.
2. **"Got the car" would silently do nothing.**
3. **The FM18 load-bearing test can't fail without the fix.**
4. **The plan uses the wrong Inertia error hook,** and wrongly claims a 419 reloads the page.

## Reachability Issues

**R1. Stale pages overwrite the newer last spot (FM24 broken).**
- Home writes `activeSpot` as `saved` on every render, with no freshness gate (plan:862-864).
- "Fresh" is defined as an initial load or a `success` event (plan:861). The design itself says those include responses served from the SW cache (design:247).
- Failure path:
  1. The user parks spot B.
  2. They reopen on a weak signal, and the SW serves the cached `/` from before parking, whose active spot is A.
  3. Home.vue writes A as the last spot, wiping B.
  4. The "Last spot you saw" card (plan:880) and `/offline` now show the **old** spot.
- If that stale Home had no active spot, `markRetrieved` marks B as retrieved instead.
- Spots/Show has the same problem when it is served from the `inertia` cache.

**R2. The unsaved-spot prompt is unreachable in the usual FM27 case.**
- After re-login, Home almost always has an active spot (the previous parking), so `writeLastSpot(...'saved')` (plan:863) overwrites the `unsaved` entry.
- `UnsavedSpotPrompt` (plan:870-874) then never appears and the captured location is lost.
- The design has the same gap (design:255 vs :219-225).

**R3. "Not saved — Retry" is wired to a hook that never fires for 4xx/5xx.**
- The plan uses `onError` "other than validation" (plan:868).
- In Inertia 3, `onError` fires only for validation errors (`@inertiajs/core/dist/index.js:2706-2710`).
- Non-Inertia 4xx/5xx responses go to `onHttpException`/`httpException`, whose default **shows a dialog** (index.js:2744-2753; default no-op at :3782).
- So plan:810's claim that a 419 "is handled by the kit's default Inertia behaviour (a page reload)" is false. A 419 or the FM18 403 shows an error modal, not the Retry state. The plan should use `onHttpException`.

**R4. The FM26 premise is wrong (harmless).**
- Workbox `NetworkFirst` does not fail on "timeout + cache miss". It keeps waiting for the network (`workbox-strategies/NetworkFirst.js`, the `|| await networkPromise` branch).
- The "cache miss → network error" path at design:530 is unreachable via the timeout. The outcome is benign, but the manual check is testing a premise that doesn't hold.

**R5. P4's household UI isn't mounted until P5.**
- P4 puts `HouseholdSwitcher` and the "Household" nav link in `AppHeader` (plan:601-603).
- But `AppLayout.vue` wraps `AppSidebarLayout` (`resources/js/layouts/AppLayout.vue:2`), and `AppHeader` is only used by `AppHeaderLayout`.
- The swap happens in P5 (plan:756), so P4's switcher can't be seen or checked manually during P4.

**R6. FM29's suggested assertion can't see the `prompt` parameter.**
- `FakeProvider::redirect()` returns a fixed `https://socialite.fake/google/authorize` (`socialite/src/Testing/FakeProvider.php:61-63`).
- `with()` is forwarded to the real provider through `__call` (:99-101), so the plan's "assert on `Socialite::fake` redirect target" (plan:317) cannot work.
- Fix: run that test un-faked. The real provider builds the Google URL without any network call.

**R7. FM17's retry branch can't be reached by either proposed test (plan:318).**
- **Primary approach:** a `creating` listener inserts a competing user inside `resolveUser`'s own `DB::transaction` (plan:285). The violation rolls that savepoint back, deleting the competitor too. The retry then creates a fresh user, so the assertion "logs in the existing user" fails.
- **Fallback:** a "seeded conflict" is found by the step-2/3 lookups, so no exception is ever thrown.
- A working approach: insert the competitor between the email lookup and the transaction, e.g. with `DB::listen` on the step-3 SELECT.

**R8. The FM28 test is unworkable on SQLite.**
- `SQLiteGrammar::compileLock` returns `''` (`Database/Query/Grammars/SQLiteGrammar.php:31-34`), so no `for update` text ever appears in `DB::listen` output (plan:618).
- Matching on the first query's table instead fails too, because accept's unlocked peek reads `household_invites` first.

## Shape Mismatches

**S1. The last-spot record has no household id.**
- `markRetrieved` matches on `household.name` (plan:864), but personal household names aren't unique ("Alex's household").
- Also, Spots/Show writes `{...spot, status}` (plan:861), but the presenter has no `household_name` and no `saved_at` (plan:700). The name match then never succeeds, so collected cars stay "saved".

**S2. Unsaved entries don't fit the `LastSpot` type.**
- `GeoResult` has numeric `lat`/`lng`/`accuracy` (plan:725), while `LastSpot` has strings (plan:834).
- `parked_at`, `parked_by` and `household_name` are required in the type but unspecified for unsaved writes (plan:866).

**S3. The `households` shared prop doesn't match its type.**
- The closure returns `null` for guests, but it is typed `{id,name}[]` (plan:298 vs :305).
- A `BelongsToMany` `get()` also serialises a `pivot` object into every item.

**S4. The map circle gets a string radius.** `SpotMap` takes `accuracy: string|number` (plan:740), and the presenter emits a decimal string, but Leaflet's `circle` `radius` is typed `number`.

**S5. The switch-household 422 doesn't apply to Inertia.** `$request->validate` on an Inertia/form PUT returns a 302 with session errors. "A missing id gets 422" (plan:610, design:193) only holds for `putJson`; the real switcher (`router.put`) never sees a 422.

**S6. The flash payload needs the `toast` key.** The kit reads `flash.toast` as `{type: 'success'|'info'|'warning'|'error', message}` (`lib/flashToast.ts`, `types/ui.ts:6-9`). Callback flashes must be `Inertia::flash('toast', [...])`, not the bare array shown at plan:270.

## Dead Branches

**D1. `HouseholdPolicy::parkIn` is never called.** The plan says it is "consumed in P5" (plan:504), but P5 authorizes with `isMemberOf` and `authorize()` returns `true` (plan:692, :703, :709). That also departs from design:155.

**D2. `HouseholdPolicy::view` is never called.** `/household` resolves through `currentHousehold()` (plan:524) and nothing else checks it.

**D3. The `assets` runtime cache never holds a precached chunk.**
- `precacheAndRoute` is registered first and its glob covers every `js/css/woff2/svg/png` (plan:814, :381), so `/build/assets/*` requests are served from the precache and never stored in `assets` (plan:826).
- See FM13 below for the consequence.

**D4. Two trivial unreachable 404s.** `findOrFail` after `exists:households,id` (plan:531, :702) can only 404 on a race, and households are never deleted.

## Failure Modes Not Ruled Out

I walked FM1–FM18 and FM23–FM29, plus I1–I8, I10 and I11.

**Hold as planned:** FM1, FM2, FM3, FM4, FM5, FM6, FM7, FM8, FM10, FM11, FM12, FM14, FM15, FM16, FM23, I2, I4, I5, I6, I7, I8, I10.

**Gaps:**

- **FM18.** The early `abort_unless(isMemberOf)` (plan:703) runs before the photo is stored and before the lock.
  - So the "non-member gets 403, no file" test (plan:762) is answered by the early check.
  - The negative control "remove the under-lock check → test fails" (plan:772) **will still pass**.
  - The under-lock check and its photo deletion have no load-bearing test. One needs membership removed between the early check and the lock.
- **FM24.** Defeated by R1.
- **FM27.** Defeated by R2 and R3.
- **FM13.** "Old chunks stay available from `assets`" (design:508) is false, per D3.
  - After an update activates, the precache drops old entries, and `vp build` replaces `public/build` on the server.
  - A stale `pages` or `inertia` copy that references old chunks then fails to load on a slow network.
- **FM9 / I11, three gaps:**
  1. **Invite-page logout can't work.** The "Sign in with a different Google account" form (plan:598) has no CSRF token, so it gets a 419, and it skips the client-side clearing. The kit has no CSRF meta tag or prop at all (grep is empty), so plan:858's "from `usePage().props` or a meta tag" has no source yet; one must be added.
  2. **History clearing can be lost.** `Inertia::clearHistory()` writes the session (`ResponseFactory.php:189-192`), and plan:801 doesn't say it must come **after** `session()->invalidate()`. Called before it, the flag is flushed and history is never cleared.
  3. **The design contradicts itself.** FM9 says "any no-user page" (design:500), but §8 says login only (design:329-335). The plan follows §8.
- **FM17.** Has no working test (R7).
- **FM26.** Rests on a wrong premise (R4).
- **FM28.** Its automated check is unworkable (R8). Only the manual pgsql check remains.
- **FM29.** The test's suggested assertion can't see `prompt` (R6).
- **FM25.** Holds.
- **I1.** Member removal checks membership (404) **before** authorizing (plan:572-573). Any outsider can probe `DELETE /households/{h}/members/{u}` and learn membership from 404 vs 403. Authorize first.
- **I3.** Holds in production. But the memo lives on the model instance, and `actingAs` reuses that instance across requests within one test. A "next request" assertion (plan:615) can read a stale memo, which contradicts the pre-review claim at plan:1011.

## Other

**O1. "Got the car" never saves.** `$spot->update(['retrieved_at' => now()])` (plan:715) runs through `fill()` (`Model.php:1188`), but `retrieved_at` isn't fillable (plan:664). The value is silently dropped, so AC5 fails. Use `forceFill` or a query update.

**O2. `$this->authorize()` doesn't exist.** The kit's base `Controller` is empty, with no `AuthorizesRequests` (`app/Http/Controllers/Controller.php`), yet the plan calls it (plan:531, :541, :573, :714-717). Use `Gate::authorize` or add the trait.

**O3. Several verification commands run on the host.** `docker compose exec laravel.test npm run build && npm run check && …` (plan:121, :962) runs everything after the first `&&` on the host, which has no `node_modules`. Wrap the chain in `sh -c '…'`.

**O4. `sw.ts` will likely break `types:check`.** `/// <reference lib="webworker" />` (plan:404) puts the webworker lib into the same program as the DOM lib, because `tsconfig.json` includes `resources/js/**/*.ts` (`tsconfig.json:16-20`, `:121-125`). Give the worker its own tsconfig and exclude it from the main one.

**O5. The kit's `ExampleTest` will break in P2.** It asserts `get(route('home'))->assertOk()` (`tests/Feature/ExampleTest.php:14-16`), but after P2 a guest is redirected. The plan doesn't list this test.

**O6. Two tests can pass without testing anything.**
- `HomeTest`'s lazy-loading assertion (plan:768) only trips when more than one model was loaded (`Eloquent/Builder.php:517-518`), so it needs at least 2 spots.
- `OfflinePageTest`'s "no session row" can't fail under the `array` session driver tests use.

**O7. Missing test and seeder pieces.**
- `CurrentHouseholdTest` "no owned household" (plan:319) is blocked by the factory's `afterCreating` hook, and the plan doesn't say how to suppress it.
- No `ParkingSpotFactory` exists for the seeder's active spot (plan:953).

**O8. The cache-name claim at plan:1005 doesn't hold as written.** The plan says the names "cannot drift", but its `sw.ts` strategies use literal `'pages'`, `'inertia'` and `'photos'` (plan:822-825) rather than `USER_CACHES`.

**O9. The README contradicts itself.** It says "Requirements: Docker Desktop", then requires a host `composer install` (plan:926-928).

**O10. The offline page ignores status.** Nothing says how a `retrieved` or `unsaved` entry is displayed there (plan:881-886), so a collected car can show as current.

**O11. Invite cancel skips the design's post-lock re-check** (design:136, plan:542-547). This is harmless, because ownership can't change.

**Checked and correct** (no action needed):
- Socialite passes `guzzle` options through (`SocialiteManager.php:253`).
- `TrustProxies` is global and reads `config('trustedproxy.proxies')` (`TrustProxies.php:69`). An empty `TRUSTED_PROXIES=` trusts nothing.
- `withRouting(then:)` exists.
- `Inertia::always` exists, the `history.encrypt` config key is right, and the page object uses `encryptHistory`.
- vite-plugin-pwa passes the Vite `define` into the SW build, so `__OFFLINE_REV__` works.
- Inertia sends `X-Inertia`, and `X-Inertia-Partial-Component` only on partial reloads.
- The kit's mail `timeout` is `null`, the local disk has `serve => true`, and the session lifetime is 120. P1/P2/P4/P5 correctly change all three.
- Sail sets `LARAVEL_SAIL=1` in `supervisord.conf`.
- The `guest` middleware falls back to the `home` route.
- A flash on the initial page load does fire the toast.
- On a bfcache restore, Inertia re-checks history encryption.
