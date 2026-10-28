I've read the design, the questions and the research. I checked the design's claims against the reference scaffold in the session scratchpad and its `vendor/` and `node_modules`, because the repo itself holds only `.gitignore` and `README.md`. I'm writing up the findings now.

## Summary

The design is thorough and its core data model is sound. I found no unworkable chosen approach and no strictly better replacement for the kit, Socialite, Postgres or the partial unique index. I did find four defects, in order of how likely they are to bite during Implement:

1. A lock-ordering deadlock between invite accept and invite cancel.
2. The FM6 mitigation depends on a `timeout` env var that `config/mail.php` doesn't read.
3. The offline-fallback design may not be expressible as written under the chosen `generateSW` mode.
4. Two cited "facts" (the `--no-reload` requirement and the unnamed-throttle claim) are wrong when checked against the scaffold's vendor code.

The design also leans on four unproven assumptions, and several behaviour changes are missing from the contract. Paths below are relative to the scratch scaffold `ref/`, as in the design.

## Approach Concerns

- **`generateSW` can't express the navigation fallback as specified (§8).**
  - The design needs a custom `handlerDidError` plugin that serves the precached `/offline` page.
  - `workbox-build` serialises `runtimeCaching[].options.plugins` with `stringifyWithoutComments`, which stringifies the function (`node_modules/workbox-build/build/lib/runtime-caching-converter.js:33-35`).
  - Under `inlineWorkboxRuntime: true` the stringified body runs in the template's module scope, so a bare `matchPrecache(...)` is a `ReferenceError` unless the template imports it.
  - It can be worked around with `caches.match('/offline', {ignoreSearch: true})`, because the precache key carries `?__WB_REVISION__=`. The design doesn't say this, and it exposes the real choice.
  - The design needs bespoke SW logic: a custom fallback, header-based route matchers and a per-user cache policy. A hand-written `injectManifest` SW is the better fit.
  - The design should either state the self-contained-function constraint or switch modes.

- **`--no-reload` is not required under Sail, so the supervisor override is unnecessary (§9, F60).**
  - `ServeCommand.php:112-120` only warns when `! option('no-reload') && ! (int) env('LARAVEL_SAIL', 0)`.
  - `vendor/laravel/sail/runtimes/8.4/supervisord.conf:10` sets `LARAVEL_SAIL="1"` for that process, so `PHP_CLI_SERVER_WORKERS=4` alone is honoured.
  - Overriding `SUPERVISOR_PHP_COMMAND` replaces the Dockerfile's `-d variables_order=EGPCS` (`runtimes/8.4/Dockerfile:16`). The design never says to keep it, so the override can silently drop it.
  - Drop the override, or keep it and preserve that flag.

- **The named-limiter rationale is wrong (§2, KD9).**
  - `ThrottleRequests::handle()` takes a `$prefix` (third argument) and builds the key as `$prefix.$this->resolveRequestSignature($request)`. `with($maxAttempts, $decayMinutes, $prefix)` exists for the same purpose.
  - `resolveRequestSignature` keys authenticated users by user id, not `domain|ip`.
  - A named limiter is still fine. But the stated justification and the "every throttled route resolves to a named limiter" test rest on a false premise.

- **The kit is `dev-main` with no recorded SHA (§1.1).**
  - `composer create-project laravel/vue-starter-kit <tmp> dev-main` produces whatever `main` is at Implement time.
  - The "fresh copy of that scaffold" can differ from the tree behind every `chisel.php:NN` and `routes/settings.php:NN` citation, and neither the design nor the research records a commit.
  - Pin the SHA, or copy the pre-spike scaffold.

- **The chisel step is partly redundant and understated.**
  - Chisel's `apply` already removes the `post-update-cmd` hook line (`chisel.php:287`).
  - It also deletes `InstallFeaturesCommand.php`, `chisel.php` and `chisel-paths.php` itself (`chisel.php:296-300`). §1.4 lists both as manual steps.
  - It runs `composer lint` and `wayfinder:generate` on the host (`chisel.php:290-292`), so it needs a working host `vendor/` and PHP.
  - With `NO_NODE`, chisel removes package lines from `package.json` (`chisel.php:52-62`) but never touches `package-lock.json`. The lock then has orphaned `@laravel/passkeys` and `vue-input-otp` entries until `npm install` runs.
  - The §1.4 note "dependency … chisel did not own (chisel.php:58-63)" has this backwards. It is the lockfile that goes out of sync.

- **The Windows bind mount is under-analysed (§1.6, R2).**
  - R2 treats the `C:\` bind mount as a watch-polling performance issue.
  - `node_modules` and `vendor` will be created by the container on NTFS, on a project outside WSL. Sail's own Windows guidance is to keep the project in the WSL2 filesystem.
  - Native binaries (`@rollup/rollup-linux-x64-gnu`, `lightningcss-linux-x64-gnu`) get written into the host tree. Symlink creation for `.bin` on a Windows-hosted mount is unverified.
  - A named volume for `node_modules` is the usual mitigation. The design relies on "never install on host" with no fallback.

## Invariants At Risk

- **I5 and I8: the "verified email" is only as fresh as the last login.**
  - Sessions are 30-day sliding (`config/session.php:35`, to be changed to 43200). An active user may never log in again.
  - `users.email` therefore stays at whatever Google reported at the last login while invite acceptance trusts it.
  - Reassigned emails (Workspace offboarding) or a changed primary address can still accept invites addressed to the old one for as long as the session slides.
  - I8 is worded "as of its last login", but I5 reads as if the match were live.

- **I11 conflicts with its own offline page.**
  - §8 clears everything on "the page has no user at all". `/offline` is a no-session page (§7).
  - `offline.ts` must be explicitly excluded from the clear, otherwise opening it wipes the `useLastSpot` entry it exists to show.
  - It also has no session to say which user's localStorage key to read, and the design never mentions a "last user id" pointer.
  - After a session *expiry* (not logout), that spot is shown to anyone who opens the app offline. This contradicts I11's "never shown under another user's session" unless the invariant is narrowed to sessions that exist.

- **I10, FM23 and the "mutations lock and re-check" rule give two inconsistent lock orders (§4, §6).**
  - Accept locks the invite row, then the household row (§6).
  - Cancel is listed under "lock the household row inside their transaction" (§4), then deletes the invite row.
  - That is invite→household against household→invite. On Postgres the two will deadlock (`40P01`) and one request returns a 500.
  - R7 says the lock ordering is "checked manually". The design should fix one global order instead: read the invite unlocked to get `household_id`, lock the household, then lock and re-read the invite.

- **I7 holds only through the app code the design itself calls non-constraint.**
  - Where a household is created in a transaction, the pivot row and `owner_id` are written together.
  - The self-heal path (`currentHousehold()` step 3) runs inside a GET render. It can be reached from any Inertia visit via the lazy shared prop, so the "I3 never mutates on read" intuition fails: GETs now write `users` rows. That is fine functionally, but the design doesn't say so.

## Failure Modes Not Mitigated

- **FM6: the 5 s SMTP timeout is not reachable from `.env`.**
  - §9 sets `.env` "`MAIL_MAILER=smtp` (mailpit:1025, timeout 5)".
  - `config/mail.php:48` hardcodes `'timeout' => null`, and there is no `MAIL_TIMEOUT` env key.
  - Without a config edit (not listed in §1 or §6), a hung SMTP server blocks the request for the default socket timeout. The invite is already committed, but the owner sees a hung page.

- **No mode for an expired session or 419 during "I parked here".**
  - The primary action is performed away from a desk, often on a weak signal.
  - A 419 or an auth redirect on `POST /spots` loses the captured fix, note and photo.
  - Re-authentication is a full-page Google redirect, which discards client state.
  - FM10 covers geolocation errors only. Add a mode for session expiry during the park POST, for example persist the pending spot before submit and restore it after sign-in.

- **A redirected XHR response is cached under the original URL (§8).**
  - The rules "cache status 200 only". An Inertia XHR that follows a redirect (`redirect: 'follow'`) is a 200 with `redirected: true`.
  - A guest `GET /` yields the login payload stored in `inertia` under `/`.
  - An authenticated `GET /login` (the `guest` middleware redirects to `/`) stores Home data under `/login`.
  - Navigations avoid this because the browser uses `redirect: 'manual'` and gets status 0. XHR visits don't.
  - Add a `cacheWillUpdate` check on `response.redirected` for the `inertia` route.

- **Flash and `errors` are replayed from cache (§8).**
  - The Google callback and a redirect-after-login navigation are fresh navigation fetches, so they are cached by `pages`. A page cached that way carries its one-shot flash (FM5 error, FM6 warning) and replays it on a later offline load.
  - The design says POST→GET redirects are never cached (FM26), which is correct, but doesn't consider these navigation-after-redirect cases.

- **The saved-copy banner can false-positive on partial reloads (§7).**
  - `generated_at` is a shared prop. A partial reload with `only` doesn't resend shared props, so `page.props.generated_at` stays old while the `success` event fires.
  - Any `router.reload({only})` (or a polled partial) older than 2 minutes shows "Showing a saved copy…" for a fresh response.
  - Either send `generated_at` in every partial or key the banner off a response header.

- **A mismatched invitee has no recovery path (§4, §6).**
  - F57 hides the invited address, so the user can't tell which account to use.
  - `Socialite::driver('google')->redirect()` doesn't force `prompt=select_account`, so a phone with one signed-in Google session can't pick another.
  - Gmail dot or plus variants (`john.doe@` vs `johndoe@`) fail the exact-match rule with the same message.
  - The design should add `prompt=select_account` and a way to sign out and retry.

- **FM2 leaves orphan files on a crash.**
  - The photo is stored before the transaction. A killed PHP worker or timeout between store and commit leaves an unreferenced file with no GC.
  - Acceptable for a small app, but the design claims "the file exists when the path is set" without the converse.

## Missed Behavior Changes

- **The OAuth limiter is 5 sign-ins/min per IP, not 10 (§2).** Redirect and callback share one named limiter keyed `google-oauth|ip`, so each sign-in spends two hits. The contract says "10/min per IP". The `invites` limiter (20/min) isn't in the contract either.

- **Ignoring `TRUSTED_PROXIES` has a production-shaped consequence.**
  - Default is no trusted proxy. Behind any load balancer `$request->ip()` is the balancer, so `google-oauth|ip` becomes one global bucket.
  - The relative `redirect` is built from the request scheme, so Google gets an `http://` redirect URI and rejects it.
  - The README plan covers upload limits but not proxy trust for real hosting.

- **`'serve' => false` removes the `/storage/{path}` route (`config/filesystems.php:36`).** This is a visible URL removal and isn't in the contract's list of 404s.

- **Test coupling to the build output (§10).**
  - "`/sw.js` serves the built file, or 404 without a build" depends on whether `public/build/sw.js` exists.
  - A test that creates or removes it will damage a real dev build, because `emptyOutDir` and the plugin both own that directory.
  - `/offline` renders `@vite`, which throws without a manifest unless the tests call `withoutVite()`.
  - Make the SW path injectable.

- **Name and avatar sync is narrower than the requirement.** `01-questions.md` says subsequent logins "update name/avatar". The design updates `avatar` and `email` but not `name`. The contract covers name editability, though not that this contradicts the requirement text.

## Other

- **AC21's "gate-before" label is weaker than it reads.** Testing it needs the scaffold, Socialite auth, Docker stack, a production build and the tunnel, which is most of the task, not a cheap early spike. If it fails, iPhone sign-in is dead and the fallback is a new design task. A throw-away route that does the same-window Google round trip behind ngrok would answer R8 in minutes.

- **Eager loading isn't specified.** Home's "last 10 spots" needs `user`, and the household member lists need loading. The design never says to eager-load or to enable `preventLazyLoading`.

- **The kit's CI is unaddressed.** `.github/workflows/tests.yml` runs `composer setup && composer ci:check` on host PHP 8.3 and Node 22. The design adapts `setup` for the container but says nothing about this workflow or `dependabot.yml`.

- **Minor accuracy nits:**
  - `.gitattributes` already ships `* text=auto eol=lf`, so §9 isn't an addition.
  - The ngrok free-tier interstitial is HTML served with a 200. A navigation could cache it under `pages['/']` in the installed app (R9 mentions the interstitial but not this).
  - The Postgres 18 volume mount (`/var/lib/postgresql`, `vendor/laravel/sail/stubs/pgsql.stub:11`) is correct as shipped. That one is fine.

- **Checked and fine:** the `--answers` JSON key `auth_features` and the `LARAVEL_INSTALLER_NO_NODE` skip (`InstallFeaturesCommand.php`, `chisel.php:42-62`), the recaller needing a string password (`SessionGuard.php:234-238`), `TrustProxies` reading `config('trustedproxy.proxies')` (`TrustProxies.php:69`), `additionalManifestEntries` running last so `/offline` stays absolute (`transform-manifest.js:41-43`), and the Inertia `Vary` handling.
