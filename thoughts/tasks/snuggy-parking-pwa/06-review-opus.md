# Review: snuggy-parking-pwa

## Summary

The diff against `f2f3f3d` only touches `README.md` and `.gitignore`. The real work is in the untracked tree, so I reviewed every file in app, routes, migrations, config, `resources/js`, `sw.ts` and tests by reading it. I didn't skim from the kit patch.

- **The backend is solid.** Every route is authorized where it is reached:
  - parking authorizes the household under its lock;
  - spots, photos and retrieve return 404 to non-members via `denyAsNotFound`;
  - invite cancel and accept lock the household before the invite;
  - the OAuth callback refuses unverified emails and never takes over an account linked to another Google id;
  - SVG uploads are rejected, and every list eager-loads its relations.
- **No P0 or P1 issues.**
- **I re-ran the gates in the container.** `php artisan test`: 131 passed, 3 skipped (688 assertions), the same as the matrix claims. `pint --test`: PASS on 93 files. I did not re-run the pgsql group, vitest or the frontend checks.
- **Matrix completeness holds.** Counted mechanically: AC1–AC22 and FM1–FM18, FM23–FM29 all have rows (AC19 and FM19–22 are withdrawn).
- **Matrix truth is weaker.** One row says "passed" for something the user must see but nobody checked (FM24). Several rows cite thinner tests than the claim needs. About ten requirements that can be checked from outside never got an AC id.
- **The main defects:**
  - the sign-in allowlist's shipped default silently breaks AC2/AC10 for anyone else, and the tests hide it;
  - the "is this a cached copy?" check uses a 2-minute window, so a cache-served page can overwrite or "collect" the spot the device just saved, inside the exact FM24 scenario.

## P2

**1. The allowlist default blocks AC2/AC10 for everyone else, and the tests are configured so it never shows.**
- `config/services.php:25-28` defaults to two hard-coded personal Gmail addresses when `GOOGLE_ALLOWED_EMAILS` is unset.
- `.env.example:72` leaves it commented out, so a fresh clone that follows the README refuses every other Google account (`GoogleAuthController.php:61-65`).
- `phpunit.xml:24` blanks it, so the AC2 row (`06-evidence.md:14`) passes only with the guard off.
- **A label promises more than its check tests:**
  - invite creation never checks the allowlist, yet `HouseholdInviteController.php:36` toasts "Invite sent to X";
  - `Household.vue:142` tells the owner "They sign in with the Google account for this address";
  - the email then invites a person who can never sign in.
- Two real people's email addresses also sit in committed source (and again in `GoogleAllowlistTest.php:7`) for a repo meant for GitHub.
- You asked for this guard, so it isn't a regression. But the AC2/AC10 rows should say "passes only with the allowlist off", and invite creation should warn (or refuse) when the email isn't on the list.

**2. The 2-minute freshness window can treat a cached page as fresh, so it overwrites or marks collected the spot just saved (FM24 turns against itself).**
- `useLastSpot.ts:34-37` decides "served from cache" purely by `generated_at` being older than 2 minutes, while `sw.ts:47-71` serves cached pages after a 3-second network timeout.
- **Scenario:**
  1. Open the app at T, so `/` is cached.
  2. Park at T+30s; the device copy is now spot S2, saved.
  3. Relaunch at T+90s on a weak signal; the worker serves the T copy.
  4. `recordResponse` (`useLastSpot.ts:211-236`) sees a "fresh" page with `activeSpot: null` and calls `markRetrieved` (`:132-142`).
- **Result:**
  - Home says "No car parked right now" with no saved-copy banner and no "Last spot you saw" card (`Home.vue:67-76` needs `stale`);
  - `/offline` later shows "This car was collected" for the car just parked.
- **The same cause** lets a cached Home holding an older active spot S1 overwrite a newer saved S2. `writeLastSpot` (`:113-127`) only guards unsaved and retrieved entries, not saved-over-saved by age.
- **Fix:** compare two server-clock values. Skip a write (or `markRetrieved`) when the page's `generated_at` is earlier than the stored entry's `parked_at`. Or have the worker tag responses it served from cache.

**3. Evidence row FM24 says "passed", but the part the user must see was never checked** (`06-evidence.md:54`).
- FM24's mitigation *is* the "Last spot you saw" card. The row admits the card was "not checked" and cites only `GeneratedAtTest` plus the banner.
- Combined with finding 2, the card can be wrong or missing inside the 2-minute window.
- The row should read partial (or gap).

## P3

**Evidence rows weaker than their claims:**
- **AC14** (`06-evidence.md:26`) claims "every app route", but `GuestRedirectTest.php:5-9` covers 3 URIs.
  - Other files add `/household`, invites and the photo route.
  - Untested as a guest: `spots.show`, store, retrieve, `PUT /current-household`, and the invite/member/leave mutations.
  - The code is correct, since everything sits in the `auth` group, but the evidence doesn't prove "every".
- **AC1:** `LoginPageTest.php:5` only checks `assertOk`. Nothing asserts that the page offers just "Continue with Google".
- **AC13:** "registers a service worker" rests on manual checks only. `ServiceWorkerRouteTest` covers serving `/sw.js`, not registration.
- **FM14** (`:48`) says "passed" while the client downscale was never exercised.
- **FM9** (`:43`): the pageshow (back/forward cache) fix has no automated test.
- **FM18** (`:52`): `LockOrderTest.php:71-89` shows that parking takes a lock. It never shows parking and member removal competing for it, and the removal tests run one after another.

**Requirements that can be checked from outside but have no AC id** (`01-questions.md` Requirements):
1. "Subsequent logins … update name/avatar". The implementation keeps the name (`GoogleAuthController.php:115`), and a test asserts the opposite of the requirement ("keeps the name"). The design changed this but 01 was never updated.
2. Personal household named "{first name}'s household" (`CreatePersonalHousehold.php:25`).
3. The switcher appears only with more than one household (`AppHeader.vue:163`). Untested.
4. Members may leave; the owner cannot. Tested by `LeaveTest`, but no AC.
5. Invites expire in 7 days, an email is sent, and a copyable link is shown.
6. A short history list (last 10).
7. Client-side photo resize and the 5 MB server limit (only FM14).
8. Denied or unavailable geolocation shows an error state (only FM10, manual).
9. The spot screen shows the accuracy circle, photo, who parked and when, and "Got the car". AC6 covers only the map, marker, note and directions.
10. Manifest theme colour, and the weak-signal caching of spot pages and tiles. AC22 covers network-off only, and is partial.

**Repeated definitions that can drift apart:**
- **"Pending invite"** is defined four times: `HouseholdInvite.php:63-71` (the scope and `isPending`), the `match` in `InviteAcceptanceController.php:30-34`, and `:65`. The two controller checks should derive from `isPending()`.
- **The directions URL** is rebuilt client-side (`useLastSpot.ts:191-192`) instead of coming from `ParkingSpot.php:84`.
- **Who sees "Remove":** `Household.vue:117` gates it on `canInvite` (the invite permission), not `removeMember`. Today both mean "owner".

**Failure states the user sees with no explanation, or that loop:**
- `ParkSheet.vue:158-161` turns every non-419 error into "Not saved — Retry".
  - For a removed member (403, FM18), or a body over `post_max_size` (413), Retry can never succeed and no reason is given.
- After a validation error (`:164-166`), `parkInFlight` clears. `UnsavedSpotPrompt.vue:86` then appears behind the open sheet, offering a "Save now" that would drop the photo.

**Missing NaN and storage guards on the client:**
- `readLastSpot` (`useLastSpot.ts:91-95`) parses localStorage without checking its shape.
- A malformed or older-version entry feeds NaN into Leaflet (`SpotMap.vue:31`, which throws), "±NaN m" on the offline page (`offline.ts:49`), and `Date.parse` comparisons.
- `setItem` swallows errors (`:48-54`). So "your location is kept on this device" (`ParkSheet.vue:271`) is shown even when nothing was stored, e.g. in a private-mode or full-quota browser.

**Invite flow edges:**
- "Sign in with a different Google account" (`Invites/Show.vue:27-31`) logs out, which drops the saved return URL (`GoogleAuthController.php:91-94`).
  - After signing in with the right account, the invitee lands on Home and must reopen the link.
  - It also wipes any unsaved park capture.
- Repeat invites to the same email aren't deduplicated (`StoreHouseholdInviteRequest.php:33-39`).
- The inviter's editable name is placed raw into a Markdown email (`household-invite.blade.php:4`). Blade escapes HTML, not Markdown, so a name like `[x](https://…)` becomes a link in the email.

**Other:**
- `bootstrap/app.php:26` exempts `logout` from CSRF, so any site can force a logout (it doesn't clear device data). This is a deliberate trade-off; noting it.
- The photo is written to `spots/{household_id}` for any integer id before authorization (`ParkingSpotController.php:35-40`). It is deleted on a 403, but a worker crash in that window leaves an orphan file under a household the user doesn't belong to.

## Consumer sweep (bar 3)

I listed everything the diff writes and grepped for its readers:
- the `users` columns, including `current_household_id` (the only reader is `currentHousehold()`, as designed);
- `household_user`, `household_invites`, `parking_spots`;
- photo files, sessions, the flash `toast`, the shared props;
- the `snuggy:*` localStorage keys and the service-worker caches.

The readers' assumptions hold: emails are lowercased at every write and compare; `auth.user` types dropped the removed fields; the toast types match. The exceptions are the ones above: the allowlist vs invites (P2-1), the freshness window vs the device copy (P2-2), and the pending/directions/owner restatements (P3).
