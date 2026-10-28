## Review: snuggy-parking-pwa

### Summary

I found no P0 or P1 defects. The backend does what the design says. I read the whole untracked tree, because `git diff f2f3f3d` shows only `README.md` and `.gitignore`.

I re-ran the checks on host PHP 8.4.15:
- `pest`: 134 tests, 131 passed, 3 skipped. This matches the matrix header.
- `pint --test`: passed.
- `phpstan`: 0 errors.
- I did not run the JS tests, because `node_modules` is not on the host. I also did not run anything in Docker.

The main gaps:
- The temporary sign-in allowlist blocks the invite feature in production, and the test suite blanks it so it never shows.
- Two evidence rows overclaim.
- The offline "last spot" copy overstates how fresh it is.
- Several requirements have no `AC` id.

**Matrix completeness:**
- The matrix has 21 AC rows and 25 FM rows. AC19 is withdrawn and counted as n/a. FM19–22 are withdrawn and have no rows.
- Nothing is missing, so the 46 active ids match the matrix's own count.
- Where a row's claim is covered, the cited tests exist.

### P2

**1. The allowlist default blocks every other user, and the tests hide it.**
- `config/services.php:27` defaults `GOOGLE_ALLOWED_EMAILS` to two hard-coded addresses. `GoogleAuthController.php:61-66` refuses everyone else. `phpunit.xml:24` sets the variable to empty, so the Pest suite never sees the restriction.
- An invitee on any other address passes `InviteCreateTest`, gets the "Invite sent to X." toast (`HouseholdInviteController.php:36`) and the copy "They sign in with the Google account for this address" (`Household.vue:142`). They then fail at `/login` with "isn't allowed to sign in".
- So AC10 and AC2 are unreachable in production for any third person, whatever the tests say. Invite creation never consults the allowlist.
- The README documents the allowlist and the user asked for it, so this is a known trade-off. It still needs either an invite-time warning or a note in `01-questions.md`.
- `.env.example` says "Unset = built-in default list", so a forgotten variable silently keeps the restriction on.

**2. AC14 is marked passed on 3 of about 20 routes.**
- `GuestRedirectTest.php:3-7` covers only `/`, `/settings/profile` and `/settings/appearance`. AC14 says "every app route".
- Other tests cover the guest case for the photo route, invite show, invite accept and `/household`.
- Nothing covers `POST /spots`, `PATCH spots/{}/retrieve`, `PUT current-household`, the invite DELETE, member DELETE or leave routes, or `GET spots/{id}`.
- All of these are inside the `auth` group (`routes/web.php:11-33`), so the code is right. Only the evidence overclaims.

**3. The offline and "last seen" copy overstates freshness.**
- The `saved` label reads "Your car is here" (`useLastSpot.ts:24`). `offline.ts:54` shows `parked_at`, not `saved_at`, and nothing on `/offline` says when the copy was taken.
- FM24 says "nothing wrong is displayed", and the saved-copy banner exists only in `AppHeaderLayout`.
- A car collected by the other driver while this phone was offline still reads "Your car is here".
- FM8 has a related hole. A removed member's device entry is cleared only by `/login`, a logout or a user change. With 30-day sessions, `/offline` keeps showing the former household's location, which cuts against I1.
- The design accepted stale caches only "under a dated banner", and `/offline` has none.

**4. Evidence rows with no failure-path test.**
- FM27 is already marked a gap. The 419 branch (`ParkSheet.vue:158-161`) and the `UnsavedSpotPrompt` Save-now/Discard path have no test.
- FM12 is also a marked gap.
- FM10, FM11 and FM13 are marked "passed" on manual checks only.
- The FM9 `pageshow` fix and the `LogoutForm` clearing have no automated test.
- FM1, FM18, FM23 and FM28 cite serial tests. No concurrent run exists, and FM28's only automated evidence is the pgsql lock-order test. That test is among the 3 skipped in the default suite.
- AC13's manifest contents (name, 192/512 icons, standalone) are not asserted anywhere. I found no automated test for the manifest.

### P3

**5. Criteria in `01-questions.md` with no `AC` id.**
- Owner removes members; members may leave; the owner cannot leave. These are covered only by FM8 and tests.
- The 7-day invite expiry. `InviteCreateTest.php:33` only asserts `isFuture()`, so a 1-minute expiry would pass.
- The email is sent and the link is copyable.
- The switcher shows only for 2 or more households (`AppHeader.vue:163`, untested).
- The server-side 5 MB photo cap.
- The recent-history list.
- Caching of spot pages and tiles for weak signal.

**6. A stale `unsaved` entry can lead to a duplicate park.**
- `ParkSheet.vue:129` writes the `unsaved` entry before the POST. `onError` (`:164`) only clears the status.
- After a 422 or a 403 (a removed member), the entry stays. Home then offers "unsaved spot — Save now" for something the user abandoned (`UnsavedSpotPrompt.vue:31`), and for a removed member it just 403s again.
- If the phone clock is more than 2 minutes ahead, a successful park's response is not "fresh" (`isFresh`). It then never replaces the `unsaved` entry. "Save now" would then retrieve the real spot and create a duplicate.
- R10 accepted the clock drift for the banner only. It did not name this consumer.

**7. Duplicated definitions that can drift apart.**
- "Pending invite" is defined three times: `scopePending` (`expires_at > now`), `isPending` (`isFuture()`), and `InviteAcceptanceController.php:32,65` (`isPast()`). At exactly `expires_at`, the list hides the invite, `destroy` refuses to cancel it, and `accept` still allows it.
- The directions URL is built in `ParkingSpot::directionsUrl` and again in `fromCapture` (`useLastSpot.ts`).
- `HouseholdPolicy::switchTo` and `parkIn` have identical bodies.

**8. Route binding leaks which ids exist.**
- `households/{household}/…` and `members/{user}` return 404 for an unknown id and 403 for an existing one, before the policy runs.
- Any authenticated user can probe household and user ids. KD3 says "403" for these.

**9. Small behaviour gaps.**
- `retrieve` always redirects to `home` (`ParkingSpotController.php:95`). A user who retrieves a spot from a household that is not their current one lands on a different household's Home.
- `logout` is CSRF-exempt (`bootstrap/app.php:26`). That allows a cross-site forced logout. The code comment accepts it.
- The requirement says "update name/avatar" on repeat logins. The callback refreshes email and avatar only (`GoogleAuthController.php:115`), and `GoogleCallbackTest` asserts the name is kept. The design chose this, but `01-questions.md` was never amended.
- Design §8 and FM13 promise a CacheFirst `assets` cache ("old chunks stay available"). `sw.ts` has none. It precaches the build and deletes `pages` and `inertia` on activate. That is coherent, but FM13's evidence doesn't describe it.
- AC15 requires the suite to pass inside the Docker container. My host run reproduces the counts, but the Docker run and the clean-clone README run (waived) are unverified by me.
