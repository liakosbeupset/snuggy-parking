# 03-design gate, reconciled

```yaml
task: snuggy-parking-pwa
gate: design
date: 2026-10-27
workflow: wf-build-e2f14e5e063f
rubric: rubrics/design.txt@22967e1bdd15
reviewers:
  - {name: opus, status: ok, model: opus-alias-unresolved, independence: same-family, degraded: ""}
  - {name: sonnet, status: ok, model: sonnet-alias-unresolved, independence: same-family, degraded: ""}
  - {name: agy, status: ok, model: gemini-3.8-flash-high, independence: cross-family, degraded: "first dispatch FAILED, pinned gemini-3.1-pro-high unknown to agy; re-dispatched once with --model gemini-3.8-flash-high"}
```

The gate is **mixed**: two same-family Claude reviewers plus one cross-family Gemini reviewer. All three reviewed **revision 1** of `03-design.md`. Revision 2 absorbs every confirmed finding below, tagged inline with `[Fn]`.

**Validator note.** `validate-review` flagged the opus and agy artifacts with its quota/login-marker check. I read both in full. They are complete, substantive reviews; the marker matched because the design's subject is Google login.

**Verification.** Load-bearing claims were checked against source in the reference scaffold, not by vote:

| Finding | Source checked |
|---|---|
| F1 | `vite-plugin-pwa/dist/index.js:166` |
| F2 | `vite-plugin-pwa/dist/index.js:838` |
| F8 | `SessionGuard.php:234-238` |
| F9, F10 | `config/filesystems.php:36-37` |
| F14 | `client/build/register.js:62` |
| F35 (refuted) | `InstallFeaturesCommand.php:20-21` |

**Cross-family signal.**
- agy independently raised or corroborated F1-F11, F14, F16-F24, F26, F28, F30-F34 and F38.
- It had no finding unique to itself.
- Its agreement on F1, F4, F5, F7 and F8 is the strongest signal at this gate.

**Unique catches.**
- opus alone: F13, F15, F23.
- sonnet alone: F25, F27, F29, F36, F37. Its only false positive was F35.

**Open for the user.**
- **F15**, iOS standalone OAuth cookie isolation. It is unverified and only testable on a real device, which is out of scope. Recorded as R8.
- **F30**, invite revocation. This is a scope choice.

```yaml
id: F1
claim: virtual:pwa-register registers buildBase+filename (/build/sw.js) with scope /, so the /sw.js route is never used and registration fails
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: vite-plugin-pwa dist/index.js:166
disposition: confirmed
basis: "call-path: dist/index.js:166 sw = options.buildBase + options.filename -> replaces __SW__ in client/build/register.js"
change: Design 8 registers /sw.js manually via workbox-window; virtual:pwa-register prohibited
```

```yaml
id: F2
claim: navigateFallback omitted; plugin default index.html is not precachable in a Blade app
kind: defect
severity: P1
reviewers: [opus, agy]
evidence: vite-plugin-pwa dist/index.js:838
disposition: confirmed
basis: "call-path: dist/index.js:838 default navigateFallback index.html, :593 adds it as additionalManifestEntries"
change: navigateFallback null stated explicitly in design 8
```

```yaml
id: F3
claim: Bootstrap order runs install:features and sail:install on the host before Docker exists, contradicting KD6
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: InstallFeaturesCommand.php:57-71; chisel.php:288-295
disposition: confirmed
basis: "call-path: InstallFeaturesCommand.php:61-71 runs npm install/build unless LARAVEL_INSTALLER_NO_NODE (chisel.php:42)"
change: Design 1 gives an explicit bootstrap with host composer once and LARAVEL_INSTALLER_NO_NODE=1
```

```yaml
id: F4
claim: Email stays editable server-side (profile rules, fillable), letting a user claim an invited address
kind: defect
severity: P1
reviewers: [opus, agy]
evidence: ProfileValidationRules.php:16-22; ProfileController.php:84-90; User.php:30
disposition: confirmed
basis: "call-path: ProfileController update fills validated email -> User fillable email -> invite accept compares users.email"
change: Email removed from fillable and profile rules; refreshed from Google each login (design 2, I8)
```

```yaml
id: F5
claim: FM16 email fallback overwrites google_id, an account-takeover path
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: 03-design rev1 FM16
disposition: confirmed
basis: "call-path: rev1 callback lookup by email then sets google_id on that row"
change: Fallback only when google_id IS NULL; different non-null google_id is refused (design 2, FM16)
```

```yaml
id: F6
claim: UserFactory and DatabaseSeeder create users with no household, so currentHousehold() has no fallback
kind: defect
severity: P1
reviewers: [opus, agy]
evidence: UserFactory.php:25-39; DatabaseSeeder.php:20-23
disposition: confirmed
basis: "call-path: UserFactory definition creates no household -> HandleInertiaRequests share would call currentHousehold()"
change: Factory afterCreating hook plus self-healing accessor (design 4, I7)
```

```yaml
id: F7
claim: current_household_id FK in the users migration references a table created later, failing on PostgreSQL
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: 0001_01_01_000000_create_users_table.php:14-22
disposition: confirmed
basis: "call-path: migration order 0001_01_01_000000 users runs before any households migration; PostgreSQL requires the referenced table"
change: Column and FK added in the households migration; migrate:fresh on pgsql in verification (design 3, R7)
```

```yaml
id: F8
claim: Remember-me never restores a user once password is dropped, so the long session is a no-op
kind: defect
severity: P1
reviewers: [sonnet, agy]
evidence: SessionGuard.php:234-238
disposition: confirmed
basis: "call-path: SessionGuard::userFromRecaller :234 getAuthPassword() null -> :236 returns before login"
change: No remember-me; SESSION_LIFETIME=43200 (KD4, contract)
```

```yaml
id: F9
claim: local disk has throw false, so a failed photo write returns false and the spot is saved anyway
kind: defect
severity: P1
reviewers: [opus, agy]
evidence: config/filesystems.php:37
disposition: confirmed
basis: "call-path: filesystems.php:37 throw false -> FilesystemAdapter put returns false instead of raising"
change: storeAs return checked, false throws (design 5, FM3)
```

```yaml
id: F10
claim: local disk has serve true, registering a signed /storage route that bypasses the photo policy if temporaryUrl is ever used
kind: defect
severity: P3
reviewers: [opus, agy]
evidence: config/filesystems.php:36
disposition: confirmed
basis: "call-path: filesystems.php:36 serve true -> FilesystemServiceProvider registers storage route"
change: serve set to false (design 4, I6)
```

```yaml
id: F11
claim: NetworkFirst with a 3 s timeout serves cached pages on slow networks, showing a superseded spot with no staleness indicator
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: 03-design rev1 section 8 and FM8
disposition: confirmed
basis: "call-path: rev1 navigation rule NetworkFirst networkTimeoutSeconds 3 falls back to cache while online"
change: generated_at shared prop plus saved-copy banner; inertia rule timeout aligned (design 7, 8)
```

```yaml
id: F12
claim: SW caches are cleared only on explicit logout, so session expiry or a different user on the device leaves prior data
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 03-design rev1 FM9
disposition: confirmed
basis: "call-path: rev1 FM9 clears caches only in the logout click handler"
change: Caches also cleared when auth.user.id changes or no user (design 8, FM9)
```

```yaml
id: F13
claim: After a deploy, cached HTML references hashed chunks that cleanupOutdatedCaches removed, giving a blank offline page
kind: defect
severity: P3
reviewers: [opus]
evidence: resources/views/app.blade.php:39
disposition: confirmed
basis: "call-path: app.blade.php:39 per-page hashed entries referenced by cached HTML; precache cleanup drops old revisions"
change: Runtime CacheFirst assets cache for /build/assets (design 8, FM13)
```

```yaml
id: F14
claim: autoUpdate reloads the page on controlling, which can discard an open confirm sheet
kind: defect
severity: P2
reviewers: [opus, agy]
evidence: vite-plugin-pwa client/build/register.js:45,62
disposition: confirmed
basis: "call-path: register.js:62 window.location.reload() on controlling when autoUpdate"
change: Prompt-style update toast, no skipWaiting or forced reload (design 8)
```

```yaml
id: F15
claim: Installed iOS web apps may not share cookies with the OAuth navigation, leaving the PWA logged out
kind: defect
severity: P3
reviewers: [opus]
evidence: 03-design rev1 section 6 intended URL claim
disposition: unresolved
basis: "Real-device behaviour not verifiable here; real-device HTTPS testing is out of scope (01-questions)"
change: Recorded as R8 and README known limitation; flagged to user
```

```yaml
id: F16
claim: Removing Fortify removes login rate limiting with no replacement on the OAuth routes
kind: improvement
severity: P3
reviewers: [opus, agy]
evidence: FortifyServiceProvider.php:98-114
disposition: confirmed
basis: "Design rev1 named no throttle on the OAuth routes"
change: throttle 10,1 on OAuth routes, 20,1 on invite create
```

```yaml
id: F17
claim: GET /invites/{token} reveals household and inviter to any logged-in token holder, in tension with AC9
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: 03-design rev1 section 6
disposition: confirmed
basis: "call-path: rev1 invite show page rendered household name and inviter without email check"
change: Details only for matching email; masked notice otherwise; named exception in I1
```

```yaml
id: F18
claim: Directions URL encoding disagrees with AC6, and decimal output differs between SQLite and PostgreSQL
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: 01-questions AC6; 03-design rev1 KD7
disposition: confirmed
basis: "call-path: rev1 KD7 asserts exact URL from uncast decimal(10,7) columns; pdo_pgsql returns numeric as string"
change: decimal:7 casts; URL built from cast values with %2C; AC6 wording revised
```

```yaml
id: F19
claim: unsignedDecimal does not exist in Blueprint and PostgreSQL has no unsigned types
kind: defect
severity: P3
reviewers: [opus, sonnet, agy]
evidence: Blueprint.php:1157
disposition: confirmed
basis: "call-path: Blueprint.php:1157 only decimal() is defined"
change: accuracy decimal(8,2) with validation min 0
```

```yaml
id: F20
claim: Manifest served from /build/ resolves relative icon paths under /build/
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: 03-design rev1 section 8
disposition: confirmed
basis: "Manifest URL resolution rule; spike used icons [] so this was never exercised"
change: Root-absolute /icons/ paths (design 8)
```

```yaml
id: F21
claim: New /login route not stated as named login; the auth middleware redirects by that name
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: Illuminate Handler.php:860-864
disposition: confirmed
basis: "call-path: Handler::unauthenticated -> redirectTo route login"
change: Route named login explicitly (design 2, contract)
```

```yaml
id: F22
claim: Rejection of the keep-Fortify alternative cites wrong grounds; reset routes are feature-gated and ignoreRoutes exists
kind: defect
severity: P3
reviewers: [opus, agy]
evidence: fortify routes/routes.php:53; Fortify.php:372
disposition: confirmed
basis: "call-path: fortify routes.php:53 wraps reset routes in Features::enabled; Fortify.php:372 ignoreRoutes"
change: Alternative restated with the correct reason
```

```yaml
id: F23
claim: The scratch scaffold contains spike edits, so copying it would carry them in
kind: defect
severity: P3
reviewers: [opus]
evidence: scratchpad ref vite.config.ts and package.json
disposition: confirmed
basis: "command: spike in 02-research edited ref/vite.config.ts and installed vite-plugin-pwa and leaflet"
change: Fresh create-project, scratch copy not used (design 1)
```

```yaml
id: F24
claim: Kit .gitignore ignores CLAUDE.md, AGENTS.md and .claude
kind: improvement
severity: P3
reviewers: [opus, agy]
evidence: ref/.gitignore:38-42
disposition: confirmed
basis: "Lines present in kit .gitignore per frontend research and agy citation"
change: Those two doc lines dropped on merge
```

```yaml
id: F25
claim: I4 claims the owner is a member as enforced, but only app code maintains it
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev1 I4
disposition: confirmed
basis: "owner_id FK enforces existence of an owner only; the pivot row is written by app code"
change: I4 reworded to state what is schema-enforced vs app-enforced
```

```yaml
id: F26
claim: Inertia partial reloads would overwrite the full-page entry in the inertia cache
kind: defect
severity: P2
reviewers: [sonnet, agy]
evidence: 03-design rev1 section 8
disposition: confirmed
basis: "call-path: rev1 inertia rule matched on X-Inertia only; a partial reload uses the same URL with X-Inertia-Partial-Data"
change: Partial requests excluded from caching
```

```yaml
id: F27
claim: Oversized uploads hit nginx/PHP limits in production as a 413, not a field error
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: sail runtimes/8.4/php.ini
disposition: confirmed
basis: "Sail php.ini sets 100M; production servers are out of scope"
change: Documented in README as a deployment requirement
```

```yaml
id: F28
claim: Parallel first-login callbacks race on unique constraints and 500
kind: defect
severity: P3
reviewers: [sonnet, agy]
evidence: 03-design rev1 section 2
disposition: confirmed
basis: "call-path: rev1 callback find-then-create without handling UniqueConstraintViolationException"
change: Catch and retry lookup once (FM17)
```

```yaml
id: F29
claim: Synchronous SMTP on single-threaded artisan serve can hang all requests
kind: defect
severity: P3
reviewers: [sonnet]
evidence: sail runtimes/8.4/Dockerfile:16
disposition: confirmed
basis: "call-path: Dockerfile:16 artisan serve single process; rev1 sync mail without timeout"
change: SMTP timeout 5 s (design 6)
```

```yaml
id: F30
claim: No way to revoke a pending invite
kind: improvement
severity: P3
reviewers: [sonnet, agy]
evidence: 03-design rev1 section 6
disposition: unresolved
basis: "Scope decision for the user; a stray invite only works for the matching verified Google account"
change: None yet; flagged for user at gate
```

```yaml
id: F31
claim: Profile name edits are overwritten by the Google name on every login
kind: defect
severity: P3
reviewers: [sonnet, agy]
evidence: 03-design rev1 section 2 step 3
disposition: confirmed
basis: "call-path: rev1 callback updates name on every login while Profile allows editing it"
change: Name set only on creation
```

```yaml
id: F32
claim: Removal list misses ProfileController email_verified_at, ProfileUpdateRequest email, Profile.vue mustVerifyEmail, types/auth.ts, UserFactory
kind: defect
severity: P2
reviewers: [sonnet, agy]
evidence: ProfileController.php:36-38
disposition: confirmed
basis: "call-path: ProfileController update sets email_verified_at null; the column is dropped by design"
change: Added to removal list (design 1 step 4)
```

```yaml
id: F33
claim: Spot photo is not runtime-cached, so the weak-signal spot view shows a broken image
kind: defect
severity: P3
reviewers: [sonnet, agy]
evidence: 01-questions PWA requirement
disposition: confirmed
basis: "call-path: rev1 runtime rules cover navigations, inertia and tiles only"
change: photos cache, cleared with the user caches
```

```yaml
id: F34
claim: Composer cleanup misses post-create-project-cmd, extra.laravel.installer and setup
kind: defect
severity: P3
reviewers: [sonnet, agy]
evidence: composer.json:81,91-95
disposition: confirmed
basis: "call-path: composer.json extra.laravel.installer post-create-project runs install:features"
change: All hooks removed, setup adapted (design 1 step 4)
```

```yaml
id: F35
claim: Chisel multiselect is interactive and --no-interaction would keep every feature
kind: defect
severity: P3
reviewers: [sonnet]
evidence: chisel.php:88-100
disposition: false-positive
basis: "command: grep signature InstallFeaturesCommand.php:20-21 -> an --answers JSON option skips the prompts"
change: Design uses --answers with empty auth_features
```

```yaml
id: F36
claim: Photo extension source unspecified; the client file name must not be trusted
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev1 section 5
disposition: confirmed
basis: "rev1 stated uuid.ext with no source"
change: Server-detected extension()
```

```yaml
id: F37
claim: currentHousehold() may write on every GET inside share(); memoise per request
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev1 section 4
disposition: confirmed
basis: "rev1 accessor persists the fallback with no memoisation"
change: Memoised per request
```

```yaml
id: F38
claim: Membership checked outside the lock; a concurrent removal lets a removed member insert a spot
kind: defect
severity: P3
reviewers: [opus, agy]
evidence: 03-design rev1 section 5
disposition: confirmed
basis: "call-path: rev1 store resolves currentHousehold before lockForUpdate; remove takes no lock"
change: Re-check after lock; remove and leave lock the same row (FM18)
```


## Round 2: revision 3 reviewed (2026-10-27)

Same lineup as round 1, all stamped as before:

| Reviewer | Model | Independence | Validation |
|---|---|---|---|
| opus | alias | same-family | ok |
| sonnet | alias | same-family | ok |
| agy | gemini-3.8-flash-high | cross-family | ok |

All three tripped the login-keyword heuristic in `validate-review`. I read every one in full; each is a complete, substantive review.

**F39 (the handoff account takeover) was structural.** I surfaced it to the user before the gate. They chose **option A**:
- withdraw the handoff;
- keep the plain same-window redirect;
- verify it on a real iPhone through the tunnel as gate-before AC21.

They also approved Laravel 13 (F51). Revision 4 absorbs everything else.

**Corroboration.** Cross-family agy corroborated F41, F63 and F64.

**Unique catches:**
- opus: F40, F55, F60, F67.
- sonnet: F43, F46, F50, F51, F52, F53, F57, F59, F61, F62, F68, F74, F75.
- agy: F45, F47, F48, F49, F54, F56, F58, F72, F73. agy's F70 and F71, and sonnet's F69, are false positives with call-path citations.

**Verified against source:**
- F64: `ThrottleRequests.php:224-230`.
- F40: `TrustProxies.php:69`.

**Still open:**
- **F68** stays `unresolved`. A manual acceptance check is planned.
- **F15** is now covered by gate-before AC21 rather than a workaround.

```yaml
id: F39
claim: Rev-3 login handoff lets any guest mint a code and phish a victim into completing it, logging the attacker in as the victim
kind: defect
severity: P1
reviewers: [opus, sonnet]
evidence: 03-design rev3 section 2 handoff, FM20
disposition: confirmed
basis: "call-path: rev3 POST handoff open to guests -> code bound to attacker session -> victim callback writes victim user_id -> attacker claim logs in as victim"
change: Handoff withdrawn (user chose option A); I9, AC19, FM19-FM22 withdrawn; AC21 gate-before device check
```

```yaml
id: F40
claim: trustProxies(at env()) in bootstrap/app.php runs before .env is loaded, so the tunnel never gets trusted proxies
kind: defect
severity: P2
reviewers: [opus]
evidence: ApplicationBuilder.php:260-265; Http/Kernel.php:170
disposition: confirmed
basis: "call-path: TrustProxies.php:69 falls back to config('trustedproxy.proxies') read at request time; env() in the withMiddleware callback runs at kernel resolution"
change: config/trustedproxy.php with env TRUSTED_PROXIES (design 9)
```

```yaml
id: F41
claim: Partial reloads using only except carry no X-Inertia-Partial-Data and get cached; the #inertia key suffix does nothing
kind: defect
severity: P3
reviewers: [opus, agy]
evidence: inertiajs/core dist/index.js:2502-2511
disposition: confirmed
basis: "call-path: core index.js:2502-2511 sends X-Inertia-Partial-Except plus X-Inertia-Partial-Component; rev3 rule excluded only X-Inertia-Partial-Data"
change: Exclude X-Inertia-Partial-Component; suffix plugin dropped (design 8)
```

```yaml
id: F42
claim: Saved-copy banner fires on every Back after 60 s, shows no date, and depends on client clock
kind: defect
severity: P3
reviewers: [opus, sonnet]
evidence: inertiajs/core dist/index.js:1768-1790
disposition: confirmed
basis: "call-path: Inertia history restore re-renders stored page props without a request, so generated_at is the original render time"
change: Evaluate only on initial load and success events; 2 min threshold; date shown; drift accepted as R10
```

```yaml
id: F43
claim: A SW registered while testing a build on localhost keeps serving cached HTML in later dev sessions
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 section 8
disposition: confirmed
basis: "rev3 registered only in production with no dev cleanup"
change: Dev mode unregisters and clears caches (design 8)
```

```yaml
id: F44
claim: Cold launch on weak signal shows Home from before parking, and reloading an XHR-only spot page offline is a browser error
kind: defect
severity: P1
reviewers: [opus, agy]
evidence: 01-questions PWA requirement; 03-design rev3 section 8
disposition: confirmed
basis: "call-path: Inertia visits store only in the inertia cache; a cold navigation to / reads pages, last filled at the previous full load; navigateFallback null gives no fallback"
change: Device-local useLastSpot plus precached /offline navigation fallback; AC22, FM24, FM25
```

```yaml
id: F45
claim: currentHousehold fallback to the owned household is not persisted, leaving a stale pointer
kind: defect
severity: P3
reviewers: [agy]
evidence: 03-design rev3 section 4
disposition: confirmed
basis: "rev3 text persisted only the create-personal-household branch"
change: Both fallbacks persist
```

```yaml
id: F46
claim: Parallel self-heal requests can each create a personal household
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 section 4
disposition: confirmed
basis: "call-path: rev3 self-heal check-then-create with no lock or uniqueness"
change: Self-heal locks the user row and re-checks
```

```yaml
id: F47
claim: Spot store resolves currentHousehold before the lock, so a removed member's spot is silently re-homed instead of 403
kind: defect
severity: P2
reviewers: [agy]
evidence: 03-design rev3 section 5, FM18
disposition: confirmed
basis: "call-path: rev3 store -> currentHousehold() fallback to owned -> lock owned household -> membership passes"
change: Store authorizes submitted household_id under lock (KD10)
```

```yaml
id: F48
claim: Email fallback to a google_id NULL user never writes google_id
kind: defect
severity: P3
reviewers: [agy]
evidence: 03-design rev3 section 2 step 3
disposition: confirmed
basis: "rev3 steps 3-5 write google_id only on create"
change: google_id set on fallback
```

```yaml
id: F49
claim: Reading user['email_verified'] directly warns and 500s when the key is absent
kind: defect
severity: P3
reviewers: [agy]
evidence: 03-design rev3 section 2 step 2
disposition: confirmed
basis: "PHP 8 undefined array key raises a warning that Laravel converts to ErrorException"
change: Null-coalesced check
```

```yaml
id: F50
claim: FM17 retry inside an aborted PostgreSQL transaction cannot succeed
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 FM17
disposition: confirmed
basis: "PostgreSQL aborts the transaction after a unique violation; further statements fail until rollback"
change: Catch and retry outside the transaction
```

```yaml
id: F51
claim: Laravel 13 / Inertia 3 contradicts the agreed Laravel 12 / Inertia v2 constraint without being flagged
kind: defect
severity: P2
reviewers: [sonnet]
evidence: 01-questions Constraints
disposition: confirmed
basis: "Constraint text read Laravel 12 / Inertia v2 while design used dev-main Laravel 13"
change: User approved Laravel 13 on 2026-10-27; 01-questions updated
```

```yaml
id: F52
claim: Bootstrap never creates .env or APP_KEY and switches PHP runtime by hand instead of --php
kind: defect
severity: P3
reviewers: [sonnet]
evidence: sail InstallCommand.php:23; .env.example:3
disposition: confirmed
basis: "--no-scripts skips post-root-package-install and post-create-project-cmd which copy .env and key:generate"
change: Explicit .env plus key:generate; sail:install --php=8.4
```

```yaml
id: F53
claim: 30-day session lives only in .env.example; config default stays 120 minutes
kind: defect
severity: P3
reviewers: [sonnet]
evidence: config/session.php:35
disposition: confirmed
basis: "call-path: config/session.php:35 env SESSION_LIFETIME default 120"
change: Config default changed to 43200
```

```yaml
id: F54
claim: Routes in routes/web.php always get the web group, so /sw.js cannot be outside it there
kind: defect
severity: P3
reviewers: [agy]
evidence: bootstrap/app.php:13
disposition: confirmed
basis: "call-path: withRouting web file is wrapped in the web middleware group"
change: /sw.js and /offline registered via withRouting then
```

```yaml
id: F55
claim: Photo route says no-store while the SW caches photos
kind: improvement
severity: P3
reviewers: [opus]
evidence: 03-design rev3 sections 4 and 8
disposition: confirmed
basis: "Cache API ignores Cache-Control, so both statements can hold but the design did not say so"
change: Stated explicitly
```

```yaml
id: F56
claim: Removing a member blindly resets their current household even when it is another household; target membership unchecked
kind: defect
severity: P3
reviewers: [agy]
evidence: 03-design rev3 section 4 outbound
disposition: confirmed
basis: "rev3 outbound text updated current_household_id unconditionally on remove"
change: Clear only when it matches; target must be a member else 404
```

```yaml
id: F57
claim: Masked invite email still reveals first letter and domain to any token holder
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 section 4
disposition: confirmed
basis: "rev3 notice included j-dot-dot-dot at gmail.com"
change: No part of the address shown
```

```yaml
id: F58
claim: Contract omits PUT current-household payload and redirects, and that members get no pending invites
kind: improvement
severity: P3
reviewers: [agy]
evidence: 03-design rev3 Behavior Contract
disposition: confirmed
basis: "Not specified in rev3"
change: Specified in sections 4 and 6
```

```yaml
id: F59
claim: Vite dev server inside the container is not reachable from the host without host 0.0.0.0
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: vite.config.ts server block
disposition: confirmed
basis: "Vite binds localhost by default inside the container"
change: server host 0.0.0.0 and hmr host localhost
```

```yaml
id: F60
claim: Single-worker artisan serve lets one slow Google call stall every request
kind: defect
severity: P3
reviewers: [opus]
evidence: ServeCommand.php:112-120; Dockerfile:16
disposition: confirmed
basis: "call-path: Dockerfile:16 artisan serve; ServeCommand.php:112 PHP_CLI_SERVER_WORKERS default 1"
change: 4 workers with --no-reload; Socialite HTTP timeouts
```

```yaml
id: F61
claim: Location history and photos are kept forever with no deletion path
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 contract
disposition: confirmed
basis: "No deletion route exists after removing account deletion"
change: Stated in Behavior Contract; deletion out of scope for this task
```

```yaml
id: F62
claim: Original photo fallback keeps EXIF GPS data
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 section 7
disposition: confirmed
basis: "Canvas re-encode strips EXIF; the fallback original does not"
change: Accepted risk R11
```

```yaml
id: F63
claim: window.open after an awaited POST loses user activation and is blocked as a popup on iOS
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: 03-design rev3 section 2
disposition: confirmed
basis: "Transient user activation does not survive an awaited network round trip in WebKit"
change: Moot - handoff withdrawn; login is a plain same-window link
```

```yaml
id: F64
claim: Unnamed throttle:N,M middleware shares one domain|ip counter across routes, so polling exhausts the callback limit
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: ThrottleRequests.php:224-230
disposition: confirmed
basis: "call-path: ThrottleRequests::resolveRequestSignature returns sha1 of domain|ip for guests; the limit is not part of the key"
change: Named RateLimiter per route group (KD9)
```

```yaml
id: F65
claim: Claim route behind guest returns an HTML redirect when the callback shared the cookie jar
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: RedirectIfAuthenticated.php handle
disposition: confirmed
basis: "call-path: RedirectIfAuthenticated redirects authenticated requests with no JSON branch"
change: Moot - handoff withdrawn
```

```yaml
id: F66
claim: I9 single-use and remaining-TTL claims are not implementable with Cache::pull and put
kind: defect
severity: P3
reviewers: [opus, sonnet]
evidence: 03-design rev3 I9
disposition: confirmed
basis: "Cache::pull is get-then-forget; the Cache API has no remaining-TTL read"
change: Moot - handoff withdrawn
```

```yaml
id: F67
claim: R4 says real-device HTTPS testing is out of scope while the tunnel and AC21 rely on it
kind: improvement
severity: P3
reviewers: [opus]
evidence: 03-design rev3 R4
disposition: confirmed
basis: "Contradictory text"
change: R4 rewritten
```

```yaml
id: F68
claim: A mutation's redirect-follow GET might be served from the SW cache on a slow network
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 section 8
disposition: unresolved
basis: "Not verifiable without a browser; expected not to happen since only GETs are routed and the POST is not intercepted"
change: FM26 plus manual acceptance check in Implement
```

```yaml
id: F69
claim: A different person logging in on a slow network can get the previous user's cached Home
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design rev3 FM9
disposition: false-positive
basis: "call-path: design section 8 user-scoped data, any no-user page (the login page every new person passes) clears pages, inertia, photos and useLastSpot before an authenticated page loads"
change: None
```

```yaml
id: F70
claim: Installed iOS app users cannot accept invites because Mail links open Safari
kind: defect
severity: P3
reviewers: [agy]
evidence: 03-design rev3 section 6
disposition: false-positive
basis: "call-path: accept writes household_user and users.current_household_id server-side -> the installed app's next request resolves currentHousehold() from the DB"
change: Clarifying sentence added to section 6
```

```yaml
id: F71
claim: lng decimal(10,7) risks overflow near +-180
kind: improvement
severity: P3
reviewers: [agy]
evidence: 03-design section 3
disposition: false-positive
basis: "numeric(10,7) holds up to 999.9999999, and validation caps lng at 180"
change: None
```

```yaml
id: F72
claim: Status for cancelling an accepted or expired invite is unspecified
kind: improvement
severity: P3
reviewers: [agy]
evidence: 03-design rev3 section 6
disposition: confirmed
basis: "Not specified"
change: Redirect back with error flash, no change
```

```yaml
id: F73
claim: Contract misses that settings routes lose the verified middleware
kind: improvement
severity: P3
reviewers: [agy]
evidence: routes/settings.php:17-31
disposition: confirmed
basis: "verified middleware disappears with email verification"
change: Stated in Behavior Contract
```

```yaml
id: F74
claim: FM1, FM18 and FM23 lock ordering cannot be proven on SQLite
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design R7
disposition: confirmed
basis: "SQLite ignores FOR UPDATE"
change: Acknowledged in R7; checked manually
```

```yaml
id: F75
claim: Removal list misses TestCase skipUnlessFortifyHas, Welcome and Dashboard usages, auth passwords broker and Password defaults
kind: defect
severity: P3
reviewers: [sonnet]
evidence: tests/TestCase.php:10-15; AppServiceProvider.php
disposition: confirmed
basis: "Files reference removed features"
change: Added to section 1 step 4
```


## Round 3: revision 4 reviewed (2026-10-27)

| Reviewer | Model | Independence | Validation |
|---|---|---|---|
| opus | alias | same-family | `validate-review` login-keyword false alarm; I read it in full and it is substantive |
| sonnet | alias | same-family | PASS |
| agy | gemini-3.8-flash-high | cross-family | PASS |

**No structural finding.** Every item was patched inline into revision 5.

**Verified against source:**
- F81: `config/mail.php:48`
- F98: `ServeCommand.php:117-119` and `supervisord.conf:10`
- F77: `config/inertia.php:19`
- F78: `ResponseFactory.php:189`, where `clearHistory` exists

**Cross-family signal.** agy alone raised F83 in part, F91, F92, F93, F96, F102, F110 and F111. It corroborated F82, F83 and F98.

**Same-family unique catches:**
- opus: F77, F78, F88, F95, F100, F105, F109, F113.
- sonnet: F84, F85, F86, F87, F89, F90, F94, F99, F101, F106, F107, F108, F112.

**Not re-reviewed.** Revision 5 was not sent through another reviewer round. Its changes are mechanical responses to these findings, and the Plan gate re-audits the design they produce.

```yaml
id: F77
claim: Kit has Inertia SSR enabled, so browser-only code added to app.ts and pages would run in Node during dev
kind: defect
severity: P2
reviewers: [opus]
evidence: config/inertia.php:18-22
disposition: confirmed
basis: "call-path: config/inertia.php:19 ssr enabled true -> @inertiajs/vite uses app.ts as SSR entry when no ssr.ts exists"
change: ssr.enabled false, build:ssr dropped (KD12)
```

```yaml
id: F78
claim: Inertia restores previous page props from history.state on Back without a request, so data survives logout and removal
kind: defect
severity: P1
reviewers: [opus]
evidence: inertiajs/core dist/index.js:1768-1790
disposition: confirmed
basis: "call-path: core handlePopstateEvent rebuilds page from state.page; kit logout is an Inertia Link"
change: history.encrypt true, clearHistory on logout, logout as full form POST; I11 extended
```

```yaml
id: F79
claim: Without prompt=select_account Google can silently reuse the previous account, so shared devices and mismatched invitees cannot switch
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 03-design rev4 section 2
disposition: confirmed
basis: "Google OAuth prompt parameter absent means the account chooser is shown only on first consent"
change: prompt select_account on redirect; FM29
```

```yaml
id: F80
claim: Accept locks invite then household while cancel locks household then invite, a PostgreSQL deadlock
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 03-design rev4 sections 4 and 6
disposition: confirmed
basis: "call-path: rev4 accept lockForUpdate invite -> household; cancel lockForUpdate household -> delete invite"
change: Global order household then invite (KD11, FM28)
```

```yaml
id: F81
claim: SMTP timeout set via .env has no effect because config/mail.php hardcodes timeout null
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: config/mail.php:48
disposition: confirmed
basis: "call-path: config/mail.php:48 timeout null, no MAIL_TIMEOUT env read"
change: config/mail.php reads MAIL_TIMEOUT default 5
```

```yaml
id: F82
claim: The park POST has no failure, timeout or expired-session path, so a weak signal or 419 loses the captured spot
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: inertiajs/core dist/index.js:3783
disposition: confirmed
basis: "call-path: core onNetworkError default noop; Inertia request sets no timeout; useLastSpot written only from server responses"
change: Pending-spot safety net (section 7), FM26, FM27
```

```yaml
id: F83
claim: Clearing on any no-user page wipes the data /offline exists to show, and /offline cannot know which user key to read
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: 03-design rev4 sections 7 and 8
disposition: confirmed
basis: "call-path: rev4 clear rule fires on pages without auth.user; /offline is session-less; useLastSpot keyed by user id"
change: Clearing only on auth/Login; snuggy:last-user pointer; I11 narrowed and stated
```

```yaml
id: F84
claim: dev-main is not pinned, so the fresh scaffold may differ from the researched tree
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 section 1
disposition: confirmed
basis: "composer create-project dev-main resolves to main at run time"
change: Pinned to c290133 via git ls-remote
```

```yaml
id: F85
claim: Chisel already removes its own files and hook and leaves package-lock.json out of sync
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: chisel.php:52-62,287-300
disposition: confirmed
basis: "Chisel apply closures delete these; NO_NODE path edits package.json only"
change: Section 1 verifies instead of repeating; lockfile resynced in container
```

```yaml
id: F86
claim: Named-limiter rationale overstated; throttle accepts a prefix and keys users by id
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: ThrottleRequests.php:224-230
disposition: confirmed
basis: "ThrottleRequests handle accepts a prefix; resolveRequestSignature keys authenticated users by id"
change: Rationale corrected; budget 20 hits = 10 sign-ins
```

```yaml
id: F87
claim: Partial reloads do not resend shared generated_at, so the banner false-positives on fresh partial responses
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 section 7
disposition: confirmed
basis: "Inertia partial reloads only send requested props unless marked always"
change: generated_at shared with Inertia::always()
```

```yaml
id: F88
claim: AC22 promised an offline map, which the OSM tile policy forbids
kind: defect
severity: P2
reviewers: [opus]
evidence: 02-research OSM policy
disposition: confirmed
basis: "OSM policy text: offline use is not permitted on tile.openstreetmap.org"
change: Offline page shows no map; AC22 reworded
```

```yaml
id: F89
claim: Kit CI workflow and setup script not addressed
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: .github/workflows/tests.yml
disposition: confirmed
basis: "Workflow runs PHP 8.3 composer setup and ci:check"
change: Workflow moved to PHP 8.4 and kept
```

```yaml
id: F90
claim: Eager loading for list screens unspecified
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 section 7
disposition: confirmed
basis: "No eager-load or preventLazyLoading stated"
change: Eager loads listed; preventLazyLoading outside production
```

```yaml
id: F91
claim: Factory personal household plus test-created households makes the lowest-id fallback pick the wrong household in tests
kind: improvement
severity: P3
reviewers: [agy]
evidence: 03-design rev4 section 4
disposition: confirmed
basis: "Fallback picks lowest owned id"
change: CreatePersonalHousehold sets current; tests set current explicitly
```

```yaml
id: F92
claim: Removing routes/settings.php:17-31 also deletes appearance.edit, which the contract keeps
kind: defect
severity: P2
reviewers: [agy]
evidence: routes/settings.php:17-31
disposition: confirmed
basis: "call-path: routes/settings.php:30 appearance.edit sits inside the removed auth+verified group"
change: Keep appearance.edit, moved to the auth group
```

```yaml
id: F93
claim: offline.ts is not a laravel-vite-plugin input, so @vite for /offline throws in production
kind: defect
severity: P2
reviewers: [agy]
evidence: vite.config.ts:12
disposition: confirmed
basis: "call-path: vite.config.ts:12 input lists only app.css and app.ts; Vite manifest lacks offline.ts"
change: offline.ts added to inputs
```

```yaml
id: F94
claim: generateSW serializes plugin functions into the template, so a custom offline fallback with Workbox imports cannot be expressed
kind: defect
severity: P2
reviewers: [sonnet]
evidence: workbox-build runtime-caching-converter.js:33-35
disposition: confirmed
basis: "call-path: runtime-caching-converter stringifies plugins; under inlineWorkboxRuntime imported helpers are not in scope"
change: Switched to injectManifest with hand-written sw.ts (KD13, R12)
```

```yaml
id: F95
claim: Offline precache revision as a build hash is unavailable when the config is evaluated, so /offline goes stale after a deploy
kind: defect
severity: P2
reviewers: [opus]
evidence: workbox-build transform-manifest.js:37-43
disposition: confirmed
basis: "Config is evaluated before chunk hashes exist"
change: __OFFLINE_REV__ defined as the build timestamp
```

```yaml
id: F96
claim: The navigation NetworkFirst rule intercepts the OAuth callback; a slow callback falls back to /offline and loses the auth code
kind: defect
severity: P1
reviewers: [agy]
evidence: 03-design rev4 section 8
disposition: confirmed
basis: "call-path: rev4 navigation route matched all mode navigate requests in scope including /auth/google/callback"
change: /auth, /login, /logout, /invites, /sw.js and non-GET never intercepted
```

```yaml
id: F97
claim: Redirected 200 responses are cached under the original URL, storing login JSON or HTML as pages and photos
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 03-design rev4 section 8
disposition: confirmed
basis: "fetch follows redirects for XHR; the final 200 has response.redirected true and passes a status-only filter"
change: cacheWillUpdate rejects redirected responses on every rule
```

```yaml
id: F98
claim: Overriding SUPERVISOR_PHP_COMMAND drops -d variables_order=EGPCS and is unnecessary under Sail
kind: defect
severity: P2
reviewers: [opus, sonnet, agy]
evidence: ServeCommand.php:117-119; Dockerfile:16
disposition: confirmed
basis: "call-path: ServeCommand.php:117-119 honours workers when LARAVEL_SAIL set; supervisord.conf:10 sets LARAVEL_SAIL 1"
change: Only PHP_CLI_SERVER_WORKERS=4; no override
```

```yaml
id: F99
claim: node_modules with Linux binaries and symlinks on the NTFS bind mount is risky
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 section 9
disposition: confirmed
basis: "Bind mount from C drive outside WSL"
change: Named volume for node_modules
```

```yaml
id: F100
claim: No Vite service or start command although the agreed stack lists Vite
kind: improvement
severity: P3
reviewers: [opus]
evidence: 01-questions Constraints
disposition: confirmed
basis: "rev4 section 9 lists three services only"
change: Dev server via docker compose exec npm run dev, documented
```

```yaml
id: F101
claim: Production without TRUSTED_PROXIES gives one global OAuth limiter bucket and http callback URLs
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 section 9
disposition: confirmed
basis: "Default trusts no proxy"
change: README production note
```

```yaml
id: F102
claim: households migration down() must drop users.current_household_id FK before dropping households on PostgreSQL
kind: defect
severity: P3
reviewers: [agy]
evidence: 03-design rev4 section 3
disposition: confirmed
basis: "PostgreSQL refuses to drop a table referenced by a FK"
change: down() order stated
```

```yaml
id: F103
claim: Tests render @vite and need a frontend build; /sw.js test cannot cover both branches against a real build
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: tests/TestCase.php; DashboardTest.php:19-25
disposition: confirmed
basis: "call-path: kit page tests render @vite without withoutVite; CI only passes because setup runs npm run build"
change: withoutVite in TestCase; injectable sw path config
```

```yaml
id: F104
claim: /household is singular and shows only the current household, not stated
kind: improvement
severity: P3
reviewers: [agy]
evidence: 03-design rev4 contract
disposition: confirmed
basis: "Not specified"
change: Stated in contract
```

```yaml
id: F105
claim: A removed member's spots stay in the household and the member loses access, not stated
kind: improvement
severity: P3
reviewers: [opus]
evidence: 03-design rev4 contract
disposition: confirmed
basis: "Not specified"
change: Stated in contract
```

```yaml
id: F106
claim: I5 reads as a live Google email match while the stored email is as of last login
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 I5
disposition: confirmed
basis: "Sliding sessions mean no re-login"
change: I5 wording plus R13
```

```yaml
id: F107
claim: FM2 claims no orphan file but a crash between store and commit leaves one
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 FM2
disposition: confirmed
basis: "Store happens before the transaction"
change: Stated as accepted
```

```yaml
id: F108
claim: AC21 gate-before sits after most of the work, so an iOS failure is discovered late
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 R8
disposition: confirmed
basis: "Gate placed after the PWA work"
change: Outline schedules AC21 right after scaffold, login and tunnel
```

```yaml
id: F109
claim: Photo rule had no network timeout, so the cached photo is not used on a weak connection
kind: defect
severity: P3
reviewers: [opus]
evidence: 03-design rev4 section 8
disposition: confirmed
basis: "NetworkFirst without networkTimeoutSeconds waits on the network"
change: networkTimeoutSeconds 3
```

```yaml
id: F110
claim: Removal list misses settings Layout.vue Security nav, Profile.vue DeleteUser usage and User.php Fortify interfaces and traits
kind: defect
severity: P2
reviewers: [agy]
evidence: settings/Layout.vue:10,19-22; Profile.vue:8,107; User.php:32,35
disposition: confirmed
basis: "call-path: Layout.vue imports @/routes/security; Profile.vue renders DeleteUser; User.php uses Fortify traits"
change: Added to section 1 step 4
```

```yaml
id: F111
claim: workbox-window and Workbox modules used directly must be explicit dependencies
kind: improvement
severity: P3
reviewers: [agy]
evidence: package.json:13-31
disposition: confirmed
basis: "Transitive dependency only"
change: Listed as explicit dependencies
```

```yaml
id: F112
claim: Pages cached after a redirect replay one-shot flash messages offline
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 03-design rev4 section 8
disposition: confirmed
basis: "Cached Inertia pages include flash"
change: Redirected responses never cached; /login and /auth excluded
```

```yaml
id: F113
claim: Device-local last spot never marked retrieved, so the offline page shows a collected car
kind: defect
severity: P2
reviewers: [opus]
evidence: 03-design rev4 section 7
disposition: confirmed
basis: "call-path: rev4 useLastSpot writes only on Spots/Show and Home with an active spot; retrieve redirects to Home without one"
change: Marked retrieved when a fresh Home has no active spot
```

```yaml
id: F114
claim: A mismatched invitee has no way to switch Google account
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 03-design rev4 section 4
disposition: confirmed
basis: "Same session cannot pick another account without prompt=select_account"
change: Mismatch page offers sign-in with a different account, combined with F79
```

```yaml
id: F115
claim: Contract misses the /storage route removal and the invite limiter
kind: improvement
severity: P3
reviewers: [sonnet, opus]
evidence: config/filesystems.php:36
disposition: confirmed
basis: "serve false removes the route; the limiter is user-visible"
change: Stated in contract
```

