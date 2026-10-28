# Critique of `03-design.md` (rev 4)

This repo holds only `README.md`, `.gitignore` and `thoughts/`, so I checked the design against the reference scaffold it says it starts from: `…\Temp\claude\C--other-snuggy-parking\4d92cb4b-…\scratchpad\ref`, written `ref/` below. Every scaffold, vendor and node_modules `file:line` below comes from reading that source. I left out anything rounds 1–2 already raised and rev 4 fixed (F1–F62).

## Summary

The core plan holds up: start from the kit, replace Fortify with Socialite, and use a hand-registered `/sw.js`. The schema, policies and locking around parking are sound. Several fixes I checked are correct:
- `additionalManifestEntries` are applied after `modifyURLPrefix`, so `/offline` stays `/offline` (`ref/node_modules/workbox-build/build/lib/transform-manifest.js:31-43`).
- `trustedproxy.proxies` is read at request time (`TrustProxies.php:69`).
- `SessionGuard.php:234-238` behaves as the design says.

What's left is mostly in the parts rev 4 added or relies on:

1. **I11 doesn't hold.** Inertia keeps every page's props in `history.state` and puts them back on Back without asking the server. The design never covers this, so a logged-out or removed user can still see household data.
2. **Shared-device and wrong-account sign-in doesn't work.** Without `prompt=select_account`, "Continue with Google" can sign the previous Google account straight back in.
3. **Saving a spot has no failure path on a weak signal.** Inertia's network-error handler does nothing by default and its requests have no timeout.
4. **Two mitigations point at the wrong place.** The SMTP timeout goes in `.env`, but `config/mail.php` hardcodes it. The `/offline` precache revision is a "build hash" that is never available when the config is evaluated.
5. **Accepting and cancelling an invite take locks in opposite orders.** FM23's promised 404 can turn into a PostgreSQL deadlock.

## Approach Concerns

**A1. Dev-mode SSR is on, and the design adds browser-only code that it will run in Node.**
- `config/inertia.php:18-22` has `ssr.enabled => true`.
- When Vite is running hot, `HttpGateway.php:50-57` skips the bundle check and posts each page to Vite's `/__inertia_ssr`.
- With no `ssr.ts`, `@inertiajs/vite` uses `resources/js/app.ts` as the SSR entry (`ref/node_modules/@inertiajs/vite/dist/index.js:503-529`).
- The design adds code to `app.ts` and the pages that cannot run outside a browser: Workbox register/unregister, `caches.*`, `localStorage` (`useLastSpot`, the I11 user-id check), and Leaflet, which touches `document`/`window` when it loads.
- Result: in dev, every Home and Spot render fails SSR and raises `SsrRenderFailed` before falling back. Pages that do render server-side can disagree with the client about the banner and the last-spot card. Tests run while `vp dev` is up also make real HTTP calls to Vite.
- **Better:** production never builds an SSR bundle, so set `ssr.enabled => false`. That is one line and removes the whole class of problems.

**A2. AC22 promises an offline map, which the OSM tile policy forbids.**
- The research quotes the policy: "Offline use is not permitted" (`02-research.md:170`), and left caching unresolved as an open question (`:229`).
- AC22 (`03-design.md:374`) and FM24 nonetheless make "the map where tiles are cached" with the network off an acceptance criterion. R6 (`:523`) only addresses prefetching.
- **Better:** offline, show coordinates, note and directions, with "map unavailable offline". If the user wants the offline map anyway, record that as an accepted risk against the quoted policy.

## Invariants At Risk

**I11, and FM8's "old spot URLs return 404": Inertia history restores user data without a request.**
- `handlePopstateEvent` (`ref/node_modules/@inertiajs/core/dist/index.js:1768-1790`) rebuilds the page from `state.page` and sends nothing to the server.
- Logout is an Inertia `<Link>` (`ref/resources/js/components/UserMenuContent.vue:44-48`). So after logout, `/login` shares a document with the earlier pages, and Back shows the previous user's spot page: note, coordinates, who parked.
- The restored props still contain `auth.user`, so the "no user → clear" rule (`03-design.md:271`) never fires.
- The same applies to a removed member pressing Back (`:397`).
- I11 (`:368`) only lists the SW caches and `useLastSpot`.
- **Fix:** turn on history encryption (`Inertia::encryptHistory()` / config) and call `Inertia::clearHistory()` on logout. Both are supported (`ref/vendor/inertiajs/inertia-laravel/src/ResponseFactory.php:189`; core `:1230`, `:1761-1767`), and neither the kit nor the design uses them.

**I11 / FM9: "a different person must pass through `/login`" doesn't guarantee they end up as a different user.**
- The authorization redirect in §2 (`:60-71`) never asks Google to show an account chooser.
- When `prompt` is absent, Google prompts only on first consent. If one Google account is still signed in on the device, the next person can come straight back in as that account. From the app's point of view it is the same user, so nothing is cleared.
- **Fix:** `Socialite::driver('google')->with(['prompt' => 'select_account'])`.

**I5 / AC11 recovery depends on the same fix.** An email-mismatched invitee is told to use a different account (`:118`). Logging out and pressing "Continue with Google" can sign them back into the same account, so they cannot recover. This is worst in an installed iOS app, which has no browser UI for signing out of Google.

**FM23 / I10: accepting and cancelling an invite lock in opposite orders.**
- Accept must read the invite by token before it knows the household, and FM7 relies on the invite-row lock (`:393-394`). Its order is therefore invite → household (`:152`).
- Cancel locks the household first (`:114`), then deletes the invite, which locks the invite row. Its order is household → invite.
- That is a lock cycle in exactly the race FM23 covers (`:422-423`). PostgreSQL aborts one side with a deadlock error, which is a 500, not the promised 404.
- **Fix:** accept reads `household_id` without a lock, locks the household, then locks and re-reads the invite.

## Failure Modes Not Mitigated

**"I parked here" fails or hangs on a weak signal, which is the app's main use case (parking garages).**
- No FM covers a failed or slow `POST /spots`; FM10 covers only geolocation.
- Inertia's `onNetworkError` defaults to `noop` (`dist/index.js:3783`). Its XHR sets no `timeout` (`:2243-2285`, only `onerror`). The user can be left on a spinner, or see no error at all.
- `useLastSpot` is written only from server responses (`03-design.md:214-216`), so the coordinates just captured are lost.
- **Mitigation:** a client-side timeout, a visible "Not saved — Retry" state, and writing the captured spot to device storage as *unsaved* when the user submits. This doesn't conflict with "offline parking is out of scope".

**FM6: the SMTP timeout is set in the wrong place.**
- §9 puts "timeout 5" in `.env` (`:284`). `ref/config/mail.php:48` hardcodes `'timeout' => null`, so `.env` has no effect.
- Symfony's socket timeout then falls back to `default_socket_timeout` (60 s). A hung SMTP server holds one of the 4 workers for a minute.
- **Fix:** add `'timeout' => env('MAIL_TIMEOUT', 5)` to `config/mail.php`.

**The precached `/offline` page goes stale after a deploy.**
- `revision: <build hash>` (`:234`) is fixed when `vite.config.ts` is evaluated, before any chunk hash exists.
- If the revision doesn't change, the SW keeps the old `/offline` HTML, which points at the old `offline-*.js`. Precache cleanup deletes that chunk, and it was never stored in the runtime `assets` cache.
- The offline page then loads with no JS, so AC22 fails silently after a deploy.
- **Fix:** derive the revision in a `manifestTransforms` entry from the offline chunk's revision (transforms run before additional entries, `transform-manifest.js:37-43`), or use a per-build timestamp.

**The device-local last spot is never marked retrieved.**
- Writes happen only when Spots/Show renders or when Home renders an active spot (`:214-216`).
- "Got the car" redirects home (`:138`), and Home with no active spot writes nothing. The same happens when another member retrieves the car.
- The offline page (`:222`) therefore keeps showing a car location that no longer applies.
- **Fix:** clear or update the stored spot whenever a fresh Home for that household has no active spot.

**The offline page conflicts with the I11 clearing rule.**
- §8 deletes user-scoped data on "any page with no user" (`:271`). `/offline` has no session (`:221`), so if `offline.ts` applies that rule, it erases the spot it exists to show.
- If it doesn't apply the rule, it still has to pick a per-user localStorage key (`:214`) without knowing the user. The design specifies neither.
- The same rule also fires if a cached `/login` is served offline.

**Redirected responses are cached under the original URL.**
- "Cache status 200 only" (`:254`) still admits redirected responses.
- With an expired session, an Inertia GET of `/spots/5` follows its 302 to the Login JSON (status 200), and the SW stores that under `/spots/5`. A `/spots/*/photo` request likewise stores the login HTML as the "photo".
- That `cache.put` completes asynchronously, so it can land after the login page has already cleared the caches.
- **Fix:** a `cacheWillUpdate` plugin that rejects `response.redirected`.

**FM12: the photo can hang offline-ish.** The `photos` rule (`:260`) has no `networkTimeoutSeconds`. On a weak but live connection the image waits on the network instead of falling back to the cache that FM12 relies on.

## Missed Behavior Changes

- **Back after logout or removal shows the previous page's data**, as described under I11. The contract says the opposite (`:397`, `:368`).
- **Visiting `/login` for any reason erases the device-local last spot**, including a redirect after the 30-day session expires (`:271`). The contract (`:343-347`) presents the last spot as something that persists on the device.
- **Invite creation is rate-limited to 20/min per user** (`:142`), and a 429 is something a user can see. The contract only mentions the OAuth limiter (`:330`).
- **A removed member's spots, notes and photos stay visible to the household, and the member loses access to their own history.** Neither the contract nor the Outbound section says so.
- **No Vite service.** `01-questions.md:47` puts Vite in the Compose stack, but §9 lists only `laravel.test`, `pgsql` and `mailpit` (`:275-280`) and never says how the dev server is started.
- **On shared devices, Google can silently re-use the previous account** (until `prompt=select_account` is added). Today the contract states that a different person can sign in.

## Other

- **The `SUPERVISOR_PHP_COMMAND` override is probably unnecessary.** `ServeCommand.php:117-119` respects `PHP_CLI_SERVER_WORKERS` without `--no-reload` when `LARAVEL_SAIL` is set. Sail sets it in both `compose.stub:17` and `runtimes/8.4/supervisord.conf`, so setting `PHP_CLI_SERVER_WORKERS=4` alone should do. The design's citation (`:278`, "ServeCommand.php:112-120") stops before the clause that bypasses this.
- **Tests depend on a front-end build.**
  - Kit page tests render `@vite` with no `withoutVite()` (`ref/tests/TestCase.php`, `ref/tests/Feature/DashboardTest.php:19-25`). CI only works because `composer setup` runs `npm run build` first.
  - The `/sw.js` test "serves the built file, or 404 without a build" (`:309`) can only exercise one of its two branches against a real `public/build`; the other needs a faked file.
  - `/offline` needs `offline.ts` in the Vite manifest.
  - AC15 needs a build step stated, or `withoutVite()` plus a fake SW file.
- **Leaflet's marker images must be precached**, or covered by the `assets` rule, for the offline map to show a marker. The design leaves `globPatterns` unspecified.

I didn't run anything on a device. The Google `prompt` behaviour and the OSM policy reading come from their documentation and the research doc, not from a test. Everything else above was checked against `ref/` source.
