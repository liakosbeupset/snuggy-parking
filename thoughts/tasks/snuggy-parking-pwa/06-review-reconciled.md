# 06-review gate, reconciled

```yaml
task: snuggy-parking-pwa
gate: review
date: 2026-10-28
workflow: wf-build-e2f14e5e063f
rubric: rubrics/final-review.txt@bce9fac7d88c
reviewers:
  - {name: opus, status: ok, model: opus-alias-unresolved, independence: same-family, degraded: ""}
  - {name: sonnet, status: ok, model: sonnet-alias-unresolved, independence: same-family, degraded: ""}
  - {name: agy, status: FAILED, model: gemini-3.8-flash-high, independence: cross-family, degraded: "quota exhausted (RESOURCE_EXHAUSTED 429, resets in ~153h); not retried"}
```

**This gate is same-family only.** agy failed on quota, so there was no cross-family reviewer. Codex is installed, but this route's dispatcher has no codex transport, and handing the tree to a third-party CLI is the user's decision; it was offered, not done.

That is why every defect below rests on my own verification. Each fix carries a negative control (the test fails with the fix reverted), or is marked as having no automated test.

**What the reviewers ran.** Both reviewers re-ran the suite independently: sonnet on host PHP, opus in the container. Both got 131 passed / 3 skipped before these fixes.

**Status.** Neither found a P0 or P1. Nothing is unresolved.
- Every defect is fixed in code or evidence.
- Improvements are listed separately and were **not** implemented. They are offered to the user.

**After the fixes:**
- `composer test`: 135 passed / 3 skipped. The pgsql group: 3 passed.
- vitest: 9 passed.
- phpstan, lint and types clean; build OK.

**Coverage, from `06-evidence.md`:** 46 ids. 38 passed, 6 partial (their remainder was waived or not exercised), 2 gaps:
- FM12: no evidence of the failure path;
- FM27: no evidence.

The gaps and the waived manual checks were accepted by the user ("waive the rest").

```yaml
id: R1
claim: The TEMPORARY sign-in allowlist breaks AC2/AC10 for everyone else, yet invite creation still says "Invite sent", and the suite runs with the guard off so it never shows
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: config/services.php allowed_emails default; StoreHouseholdInviteRequest had no allowlist check; phpunit.xml GOOGLE_ALLOWED_EMAILS=""
disposition: confirmed
basis: "test: GoogleAllowlistTest 'inviting an email outside the allowlist is refused' — fails with the new rule disabled (control run)"
change: App\Support\SignInAllowlist::allows() shared by GoogleAuthController::callback and StoreHouseholdInviteRequest; invites to non-allowlisted emails are a validation error; AC2/AC10 evidence rows now name the allowlist. At the user's choice before pushing to the public repo, the emails moved out of source into GOOGLE_ALLOWED_EMAILS; the guard now fails closed (unset/empty = nobody, "*" = anyone).
```

```yaml
id: R2
claim: The 2-minute freshness window treats a worker-served cached page as fresh, so it can "collect" or overwrite the spot just parked (FM24 turns against itself)
kind: defect
severity: P2
reviewers: [opus]
evidence: useLastSpot.ts recordResponse used only Date.now()-generated_at; sw.ts NetworkFirst serves cache after 3 s
disposition: confirmed
basis: "test: deviceData.test.ts 'a cached Home from before the park neither collects nor replaces the saved spot' — fails with the guard disabled (control run)"
change: recordResponse skips any write when the page's generated_at predates the stored saved/retrieved spot's parked_at (both server clock)
```

```yaml
id: R3
claim: Evidence row FM24 says passed although the user-visible part (the "Last spot you saw" card) was never checked
kind: defect
severity: P2
reviewers: [opus]
evidence: 06-evidence.md FM24 row
disposition: confirmed
basis: "call-path: 06-acceptance.md FM24 row records the card as not checked"
change: FM24 row is now partial; the card check is listed as waived
```

```yaml
id: R4
claim: The offline copy says "Your car is here" with no date, so a stale copy reads as current (design accepted stale copies only under a dated banner)
kind: defect
severity: P2
reviewers: [sonnet]
evidence: offline.ts rendered parked_at only; the saved-copy banner exists only in AppHeaderLayout
disposition: confirmed
basis: "call-path: offline.ts card -> STATUS_LABEL + parked_at only; /offline does not load AppHeaderLayout"
change: /offline shows "Saved on this device <saved_at> — may be outdated". No automated test (offline.ts has no DOM test); not re-checked in a browser
```

```yaml
id: R5
claim: AC14 claims every app route redirects guests, but tests covered 3 URIs
kind: defect
severity: P3
reviewers: [opus, sonnet]
evidence: GuestRedirectTest.php
disposition: confirmed
basis: "test: GuestRouteSweepTest iterates every route carrying `auth` and asserts /login — passes (the code was already right; the evidence was not)"
change: GuestRouteSweepTest added; AC14 row cites it
```

```yaml
id: R6
claim: The "pending invite" rule is restated in the controllers with isPast(), so at exactly expires_at the list hides the invite while accept still allows it
kind: defect
severity: P3
reviewers: [opus, sonnet]
evidence: InviteAcceptanceController.php:32,65 vs HouseholdInvite::isPending()/scopePending
disposition: confirmed
basis: "test: InviteAcceptTest 'an invite at exactly its expiry moment is expired everywhere' — fails with isPast() restored (control run)"
change: both controller checks use ! expires_at->isFuture(), matching isPending()
```

```yaml
id: R7
claim: User-editable names are interpolated raw into the Markdown invite email, so a name like "[x](https://…)" becomes a link in mail the app sends to arbitrary addresses
kind: defect
severity: P3
reviewers: [opus]
evidence: resources/views/mail/household-invite.blade.php; HouseholdInviteMail::content
disposition: confirmed
basis: "test: InviteCreateTest 'a name with Markdown stays text in the invite email' — fails with the escaping removed (control run)"
change: HouseholdInviteMail::literal() backslash-escapes Markdown in inviter and household names
```

```yaml
id: R8
claim: ParkSheet turns every non-419 error into "Not saved — Retry", so a removed member (403) or an oversized body (413) retries forever with no reason
kind: defect
severity: P3
reviewers: [opus]
evidence: ParkSheet.vue onHttpException
disposition: confirmed
basis: "call-path: ParkSheet.vue onHttpException -> status 'failed' for any status != 419"
change: 403 shows "you can no longer park in this household", 413 shows "the photo is too large"; no automated test (no component tests in the suite)
```

```yaml
id: R9
claim: With a phone clock >2 min fast a successful park is never "fresh", so the unsaved capture survives and Home offers "Save now", creating a duplicate spot
kind: defect
severity: P3
reviewers: [sonnet]
evidence: ParkSheet.vue writes unsaved before POST; useLastSpot isFresh gates the replacement
disposition: confirmed
basis: "call-path: ParkSheet submit -> writeLastSpot(unsaved) -> success -> recordResponse returns early when !fresh -> UnsavedSpotPrompt shows the capture"
change: ParkSheet onSuccess removes the entry when it is still 'unsaved' (the server has the spot); no automated test
```

```yaml
id: R10
claim: Several evidence rows cite thinner tests than their claim (FM14 client downscale, FM18 concurrent form, AC1 page content, AC13 registration, FM9 pageshow fix, FM13 assets-cache wording)
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: 06-evidence.md
disposition: confirmed
basis: "call-path: rows re-read against the cited tests"
change: FM14 now partial, FM18 notes serial-only, FM13 describes the precache-based behaviour; AC1/AC13/FM9 remain as stated with their manual checks
```

```yaml
id: R11
claim: Several Requirements in 01-questions.md have no AC id (leave/owner-cannot-leave, 7-day expiry, recent list, switcher visibility, 5 MB cap, spot-screen contents, name refresh vs design)
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: 01-questions.md Requirements
disposition: confirmed
basis: "call-path: grep of AC ids in 01-questions.md vs Requirements list"
change: none — offered to the user
```

```yaml
id: R12
claim: Duplicated definitions (directions URL client and server, switchTo/parkIn identical bodies, Remove button gated on canInvite)
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: useLastSpot.ts fromCapture; ParkingSpot::directionsUrl; HouseholdPolicy; Household.vue
disposition: confirmed
basis: "call-path: both definitions read; they agree today"
change: none
```

```yaml
id: R13
claim: households/{household}/… and members/{user} route binding answers 404 for unknown ids and 403 for existing ones, an id-existence oracle
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: routes/web.php implicit binding before the policy
disposition: confirmed
basis: "call-path: implicit model binding resolves before authorize()"
change: none — sequential ids, low value; offered
```

```yaml
id: R14
claim: Client-side hardening and UX edges (localStorage shape not validated, "kept on this device" shown when storage failed, different-account sign-in drops the invite return URL, repeat invites not deduplicated, retrieve redirects to the current household's Home, removed member's device copy persists offline, photo written before authorization)
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: useLastSpot.ts readLastSpot/setItem; Invites/Show.vue; StoreHouseholdInviteRequest; ParkingSpotController
disposition: confirmed
basis: "call-path: each read in source"
change: none — offered
```

```yaml
id: R15
claim: logout is CSRF-exempt, so any site can force a logout
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: bootstrap/app.php validateCsrfTokens(except logout)
disposition: confirmed
basis: "call-path: deliberate design choice (approved plan, logout works after session expiry); noted, not changed"
change: none
```

## Notes per finding

- **R1** — Opus and sonnet raised it independently. The user asked for the allowlist, so the guard itself stays. What changed is that invites can no longer promise access the allowlist refuses.
- **R2** — Only opus raised it. It was the sharpest catch of this gate: inside the very weak-signal scenario FM24 exists for, the device's own safety net would have marked a car that had just been parked as collected.
- **R4, R9** — Only sonnet raised these. Both are user-visible offline and weak-signal edges.
- **R8, R9** — These fixes have no automated test. The repo has no component-test harness, and building one would widen scope.
- **Improvements R10–R15** — None were implemented, per the gate rule. Each is available if the user asks.
