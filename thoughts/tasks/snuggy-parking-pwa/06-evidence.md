# Evidence matrix

Seeded from the plan's `Satisfies:` lines plus post-plan changes. "Manual" rows cite `06-acceptance.md`.
Last full run (2026-10-28, after review fixes): `composer test` 135 passed / 3 skipped (pgsql group, 3 passed on pgsql), phpstan L7 0 errors, `npm run test:js` 9 passed, `npm run check` + `types:check` clean, build OK.

## Review scope (read this first)

The repo's base commit `f2f3f3d` holds only README.md, and the work is uncommitted, so `git diff <base>` shows almost nothing and `git status` collapses to `?? app/` etc. Everything outside vendor/node_modules is this task's work, but most of it is the unmodified scaffold of `laravel/vue-starter-kit@c290133`.
**`06-task-vs-kit.patch`** in this folder is the diff of the working tree against that pristine kit (vendor, node_modules, lockfiles, public/build, generated Wayfinder `resources/js/{actions,routes,wayfinder}`, binaries excluded; deleted kit files listed by name only). That is the code this task wrote or changed. Read files in the repo for context.

| ID | Code path | Evidence | Result |
|---|---|---|---|
| AC1 | `routes/auth.php`, Fortify removed | `tests/Feature/Auth/LoginPageTest.php` (password/2FA/passkey URIs 404, POST /login 405) | passed |
| AC2 | `GoogleAuthController::callback/resolveUser`, `CreatePersonalHousehold` | `GoogleCallbackTest` first/repeat login (suite runs with the TEMPORARY allowlist off); `GoogleAllowlistTest` allowlisted login; manual P2 rows | passed — while the allowlist is on, only its emails can sign in |
| AC3 | `ParkingSpotController::store` | `StoreSpotTest` "parking stores the spot…", "note and photo are optional"; manual P5 park row | passed |
| AC4 | store retrieves previous active spot under lock | `StoreSpotTest` "parking again retrieves the previous active spot" | passed |
| AC5 | `ParkingSpotController::retrieve` (query update) | `RetrieveSpotTest` "got the car", "retrieving twice…"; manual "Got the car" row | passed |
| AC6 | `ParkingSpot::directionsUrl`, `SpotMap.vue`, `Spots/Show.vue` | `ShowSpotTest` member test (`destination=51.5000000%2C-0.1200000`); manual map + directions rows | passed |
| AC7 | `ParkingSpotPolicy` denyAsNotFound | `ShowSpotTest`/`RetrieveSpotTest`/`SpotPhotoTest` non-member 404 | passed |
| AC8 | `HouseholdPolicy::switchTo/parkIn` | `SwitchHouseholdTest`; `StoreSpotTest` non-member/unknown/removed-member 403 | passed |
| AC9 | `HouseholdPolicy` invite/remove, owner-only `pendingInvites` | `InviteCreateTest`, `HouseholdPageTest`, `MemberRemovalTest` | passed |
| AC10 | `InviteAcceptanceController` | `InviteAcceptTest` "a matching user accepts…", single-use tests; invites to non-allowlisted emails refused up front (`GoogleAllowlistTest`) | passed (manual accept by a second account waived) |
| AC11 | acceptance refusals | `InviteAcceptTest` expired / already used / email mismatch; manual wrong-account view hides household | passed |
| AC12 | `/spots/{id}/photo`, private disk, `serve => false` | `SpotPhotoTest` | passed |
| AC13 | manifest, `/sw.js` route, `sw.ts`, `pwa/register.ts` | `ServiceWorkerRouteTest`, `OfflinePageTest`; manual: manifest valid, SW activated scope `/`, update toast + Reload | passed |
| AC14 | `auth` middleware | `GuestRouteSweepTest` (every route carrying `auth`, any verb, → /login), `GuestRedirectTest` | passed |
| AC15 | toolchain | `composer test` + pgsql group + `test:js` + check/types/build (counts above); SQLite-file and pgsql migrate/rollback (P7) | passed (clean-clone README run waived) |
| AC16 | partial unique index `parking_spots_one_active_per_household` | `ActiveSpotIndexTest` (SQLite + pgsql) | passed |
| AC17 | `User::currentHousehold()` self-heal | `CurrentHouseholdTest` | passed |
| AC18 | verified-email check | `GoogleCallbackTest` unverified dataset | passed |
| AC19 | withdrawn (design rev 4) | — | n/a |
| AC20 | `HouseholdInviteController::destroy` | `InviteCancelTest`; manual cancel → link 404 | passed |
| AC21 | ngrok `tunnel` profile, trusted proxies | manual P3 AC21 row (real iPhone, installed app) | passed |
| AC22 | `/offline` route + `offline.ts` + `useLastSpot`; dated "Saved on this device … may be outdated" line | `OfflinePageTest` (200, no cookie); manual: /offline renders last spot, precached | partial — true airplane-mode reopen on phone waived; dated line not re-checked in a browser |
| FM1 | lock household + partial unique index | `ActiveSpotIndexTest`, pgsql `LockOrderTest` spot case | passed |
| FM2 | delete stored photo on failure | `PhotoFailureTest` FM2 | passed |
| FM3 | `throw_if($path === false)` | `PhotoFailureTest` FM3 | passed |
| FM4 | `email_verified !== true` refusal | `GoogleCallbackTest` unverified dataset (false, missing, "true", no email) | passed |
| FM5 | callback error/state/Throwable → /login toast | `GoogleCallbackTest` cancelled / invalid state / API failure | passed |
| FM6 | mail failure keeps invite, warns | `InviteCreateTest` "a failing mailer…" | passed |
| FM7 | single-use accept under lock | `InviteAcceptTest` same-user redirect, second-user "already used" | passed |
| FM8 | pointer cleared only if equal, self-heal | `MemberRemovalTest`, `LeaveTest` | passed |
| FM9 | `LogoutForm` clears caches + spot; `clearHistory` + history encryption; `app.ts` pageshow reload | `LogoutClearsHistoryTest`, `HistoryEncryptionTest`, vitest `deviceData.test.ts`; manual: caches/spot cleared; Back leak FOUND and fixed (pageshow), re-tested → /login | passed (fix has no automated test; second-account part waived) |
| FM10 | `useGeolocation` messages + Retry | manual (denial stubbed): "No location — Location access is blocked…" + Retry | passed (no automated test) |
| FM11 | >50 m warning | manual (accuracy 120 stubbed): warning shown, park allowed | passed (no automated test) |
| FM12 | grey map fallback | not exercised (tiles loaded); code-level only | gap — no evidence of the failure path |
| FM13 | SW waits (no `assets` runtime cache: the precache is the old build until the new SW activates, `activate` drops `pages`/`inertia`); persistent "Update available" toast | manual: toast auto-dismissed in 4 s FOUND and fixed (`duration: Infinity`), Reload activates + reloads | passed (no automated test) |
| FM14 | server validation + client downscale; 413 message in `ParkSheet` | `StoreSpotTest` photo dataset (pdf, gif, >5 MB) | partial — client downscale and 413 message not exercised |
| FM15 | existing-member refusal, no duplicate pivot | `InviteCreateTest`, `InviteAcceptTest` | passed |
| FM16 | no google_id takeover | `GoogleCallbackTest` linked-to-other / refresh collision | passed |
| FM17 | unique-violation retry | `GoogleCallbackRaceTest` | passed |
| FM18 | `parkIn` under household lock; 403 message in `ParkSheet` | `StoreSpotTest` non-member/removed-member (serial); pgsql `LockOrderTest` shows the lock is taken | passed (no concurrent removal-vs-park run) |
| FM23 | re-read invite after lock | `InviteCancelTest` accept 404 after cancel (serial form) | partial — concurrent form manual, waived |
| FM24 | `generated_at` via `Inertia::always`, banner; cached pages older than the stored spot never overwrite it | `GeneratedAtTest`; vitest "a cached Home from before the park…"; manual: banner observed | partial — "Last spot you saw" card not checked |
| FM25 | `handlerDidError` → precached `/offline` | `/offline` precache verified in Chrome | partial — offline reload waived |
| FM26 | unsaved write before POST, `okOnly` rejects redirected | vitest unsaved-protection test | partial — UI manual waived |
| FM27 | 419 branch in `ParkSheet` | none automated | gap — manual waived |
| FM28 | lock order household → invite | pgsql `LockOrderTest` accept + cancel | passed (concurrent tinker check waived) |
| FM29 | `prompt=select_account`; device data cleared on user change | `GoogleRedirectTest`; vitest `syncDeviceUser` tests | passed |

## Post-plan changes

| Change | Code path | Evidence |
|---|---|---|
| SQLite as production DB [USER] | `config/database.php` sqlite block | `SqliteTransactionModeTest` (fails under DEFERRED) |
| Collected old spot does not overwrite device copy [ORCH-P6] | `useLastSpot.writeLastSpot` | vitest "viewing an older collected spot…" (fails with guard off) |
| TEMPORARY sign-in allowlist [USER] | `config/services.php` `google.allowed_emails`, `GoogleAuthController::callback` | `GoogleAllowlistTest` (2 refusal tests fail with guard off) |
| bfcache logout leak (FM9), persistent update toast (FM13) [ORCH-ACC] | `resources/js/app.ts` | manual re-test in Chrome (no automated test) |
| Kit leftovers removed | `AppHeader.vue`, `AppLogo.vue`, `AppLogoIcon.vue` | manual Chrome check |
| Test env pinned | `phpunit.xml` APP_URL / TRUSTED_PROXIES / GOOGLE_ALLOWED_EMAILS | suite green with tunnel `.env` active |

**Coverage:** 46 active ids (AC1–AC22 minus AC19, FM1–FM18, FM23–FM29). 38 evidenced as passed, 6 partial (AC22, FM14, FM23, FM24, FM25, FM26 — remainder waived or unexercised), 2 gaps (FM12 no failure-path evidence, FM27 no evidence).
