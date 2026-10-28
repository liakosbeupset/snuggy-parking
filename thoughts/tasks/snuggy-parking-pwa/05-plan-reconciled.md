# 05-plan gate, reconciled

```yaml
task: snuggy-parking-pwa
gate: plan
date: 2026-10-27
workflow: wf-build-e2f14e5e063f
rubric: rubrics/plan.txt@bb8110bd9849
reviewers:
  - {name: opus, status: ok, model: opus-alias-unresolved, independence: same-family, degraded: ""}
  - {name: sonnet, status: ok, model: sonnet-alias-unresolved, independence: same-family, degraded: ""}
  - {name: agy, status: ok, model: gemini-3.8-flash-high, independence: cross-family, degraded: ""}
```

The gate is **mixed**: two same-family Claude reviewers and one cross-family Gemini reviewer.

**How the reviewers worked.**
- opus and sonnet each built the pinned kit `c290133` in scratch dirs and executed parts of the plan.
- sonnet reproduced PA-O1 (`retrieved_at` silently dropped) and PA-S3 (`pivot` in the shared prop), and ran Larastan on the planned signatures (PA-6).

**My own source checks:**
- PA-1: Sail `compose.stub:8`, `Dockerfile:89`.
- PA-3: `InteractsWithDockerComposeServices.php:292-304`.
- PA-2: the empty base Controller.
- PA-5c: no csrf meta or prop.

**Result.**
- No design problem was found. Every finding was mechanical and has been absorbed into `05-plan.md`, with each change tagged `[PA-…]`.
- None is unresolved.

**Cross-family.** agy independently raised PA-2, PA-5c, PA-S1 and PA-D1. It alone caught PA-agy-S2, PA-agy-R1 and PA-agy-R3, the last a real reachability bug in the unsaved prompt.

**Same-family unique catches:**
- sonnet: PA-1, PA-3, PA-4, PA-5, PA-6, PA-R4b, PA-S2, PA-S4b, PA-limiter, PA-lock, PA-ports, PA-I1b.
- opus: PA-O3, PA-O6, PA-O8, PA-O9, PA-O10, PA-R1, PA-R4, PA-S5, PA-D3, PA-I3, PA-O11.

```yaml
id: PA-1
claim: Plain docker compose build leaves WWWGROUP unset, so the Sail image fails to build
kind: defect
severity: P1
reviewers: [sonnet]
evidence: sail stubs/compose.stub:8; runtimes/8.4/Dockerfile:89
disposition: confirmed
basis: "call-path: compose.stub:8 passes WWWGROUP build arg -> Dockerfile:89 groupadd -g $WWWGROUP; only bin/sail exports it"
change: WWWUSER/WWWGROUP=1000 in .env (P1); exec with -u sail
```

```yaml
id: PA-2
claim: Base Controller has no AuthorizesRequests, so every $this->authorize() call 500s
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: app/Http/Controllers/Controller.php
disposition: confirmed
basis: "call-path: ref app/Http/Controllers/Controller.php is an empty abstract class"
change: Trait added in P2; global convention
```

```yaml
id: PA-3
claim: sail:install rewrites phpunit.xml to pgsql, and CI copies a pgsql .env.example with no pgsql service
kind: defect
severity: P1
reviewers: [sonnet]
evidence: InteractsWithDockerComposeServices.php:292-304
disposition: confirmed
basis: "call-path: configurePhpUnit preg_replace removes DB_CONNECTION line and sets DB_DATABASE testing"
change: phpunit.xml restored to sqlite memory; CI env DB_CONNECTION sqlite
```

```yaml
id: PA-4
claim: Dev DB stays on the P1 schema after P2 edits the users migration in place
kind: defect
severity: P2
reviewers: [sonnet]
evidence: 05-plan P2
disposition: confirmed
basis: "P1 migrates the kit users table; P2 edits the migration; nothing reran it before P7"
change: migrate:fresh at end of P2
```

```yaml
id: PA-5
claim: Adding a second server key in vite.config.ts duplicates the kit's existing server block
kind: defect
severity: P3
reviewers: [sonnet]
evidence: vite.config.ts:34-44
disposition: confirmed
basis: "call-path: 02-research notes vite.config.ts:34-44 dev watch ignore list under server"
change: Merge into the existing block
```

```yaml
id: PA-5c
claim: Native logout and invite forms have no CSRF token source
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: resources/views/app.blade.php; HandleInertiaRequests.php
disposition: confirmed
basis: "command: grep -n csrf resources/views/app.blade.php app/Http/Middleware/HandleInertiaRequests.php -> no matches"
change: csrf-token meta tag in P2; logout CSRF-exempt; LogoutForm component
```

```yaml
id: PA-6
claim: Relation signatures without generics and missing @property fail phpstan level 7
kind: defect
severity: P2
reviewers: [sonnet]
evidence: phpstan.neon:16
disposition: confirmed
basis: "Kit runs Larastan at level 7, which requires relation generics and known properties"
change: Global convention for generics and property docblocks
```

```yaml
id: PA-8
claim: clearHistory writes a session flag that invalidate() flushes if called first
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: ResponseFactory.php:189-192
disposition: confirmed
basis: "call-path: ResponseFactory::clearHistory sets session flag; session invalidate clears session data"
change: Order fixed - clearHistory after invalidate and regenerateToken
```

```yaml
id: PA-O1
claim: retrieved_at is not fillable, so update() silently drops it and Got the car does nothing
kind: defect
severity: P1
reviewers: [opus, sonnet]
evidence: Model.php:1188
disposition: confirmed
basis: "call-path: Model::update -> fill() discards non-fillable keys; plan fillable list omitted retrieved_at"
change: Atomic query update; negative control added
```

```yaml
id: PA-O3
claim: Chained docker compose exec commands run everything after the first && on the host
kind: defect
severity: P3
reviewers: [opus]
evidence: 05-plan verification blocks
disposition: confirmed
basis: "Shell splits && before docker compose exec sees it"
change: sh -c wrapper convention
```

```yaml
id: PA-O4
claim: sw.ts webworker lib merges into the DOM program and breaks types:check
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: tsconfig.json include resources/js
disposition: confirmed
basis: "tsconfig includes all resources/js ts files with DOM lib"
change: Separate tsconfig.sw.json; types:check runs both
```

```yaml
id: PA-O6
claim: HomeTest lazy-load and OfflinePageTest session-row assertions cannot fail
kind: defect
severity: P3
reviewers: [opus]
evidence: Eloquent/Builder.php:517-518
disposition: confirmed
basis: "preventLazyLoading triggers only with more than one hydrated model; array session driver writes no rows"
change: 3+ spots by 2 users; session-row check dropped
```

```yaml
id: PA-O7
claim: No-owned-household test blocked by factory hook; no ParkingSpot factory for the seeder
kind: defect
severity: P3
reviewers: [opus, sonnet]
evidence: 05-plan P2 and P7
disposition: confirmed
basis: "afterCreating always creates a household; seeder references an unspecified factory"
change: withoutPersonalHousehold state; ParkingSpot and HouseholdInvite factories
```

```yaml
id: PA-O8
claim: sw.ts uses literal cache names, contradicting the cannot-drift claim
kind: improvement
severity: P3
reviewers: [opus]
evidence: 05-plan P6
disposition: confirmed
basis: "Plan strategies used string literals"
change: CACHE constants module used everywhere
```

```yaml
id: PA-O9
claim: README requires host composer while claiming Docker-only
kind: improvement
severity: P3
reviewers: [opus]
evidence: 05-plan P7
disposition: confirmed
basis: "Contradictory text"
change: laravelsail/php84-composer docker run for vendor
```

```yaml
id: PA-O10
claim: Offline page ignores retrieved and unsaved status
kind: defect
severity: P3
reviewers: [opus]
evidence: 05-plan P6
disposition: confirmed
basis: "offline.ts rendering did not branch on status"
change: Status-specific rendering
```

```yaml
id: PA-R1
claim: Stale cache-served pages overwrite a newer device-local last spot or mark it retrieved
kind: defect
severity: P1
reviewers: [opus]
evidence: 05-plan P6 write wiring
disposition: confirmed
basis: "call-path: design section 7 banner treats SW-served responses as success events; Home wrote activeSpot on every success"
change: Freshness gate (120 s) on every write
```

```yaml
id: PA-R2
claim: A fresh Home with an older active spot overwrites the unsaved capture, so the prompt never shows
kind: defect
severity: P1
reviewers: [opus, sonnet]
evidence: 05-plan P6 Home wiring
disposition: confirmed
basis: "call-path: plan Home wrote saved for any activeSpot with no unsaved guard"
change: Unsaved protection rule - replaced only by evidence it was saved
```

```yaml
id: PA-R3
claim: onError fires only for validation in Inertia 3; HTTP errors show a dialog, so Not saved never appears
kind: defect
severity: P1
reviewers: [opus, sonnet]
evidence: inertiajs/core dist/index.js:2706-2710,2744-2753
disposition: confirmed
basis: "call-path: core onError gated on non-empty props.errors; httpException default shows dialog"
change: onHttpException and onNetworkError handled explicitly
```

```yaml
id: PA-R4
claim: FM26 premise wrong - NetworkFirst waits for the network on a cache miss
kind: improvement
severity: P3
reviewers: [opus]
evidence: workbox-strategies NetworkFirst
disposition: confirmed
basis: "NetworkFirst falls back to awaiting networkPromise when cache misses"
change: Manual check note updated
```

```yaml
id: PA-R4b
claim: A session-expired POST gives 419 (CSRF before auth), not a redirect to login
kind: defect
severity: P2
reviewers: [sonnet]
evidence: bootstrap web middleware order
disposition: confirmed
basis: "VerifyCsrfToken is in the web group and runs before route auth middleware"
change: 419 branch in the park sheet; logout CSRF-exempt
```

```yaml
id: PA-R5
claim: P4 switcher and nav are added to AppHeader, which is not mounted until the P5 layout switch
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: resources/js/layouts/AppLayout.vue:2
disposition: confirmed
basis: "call-path: AppLayout.vue wraps AppSidebarLayout; AppHeader is used only by AppHeaderLayout"
change: Layout switch moved to P2
```

```yaml
id: PA-R6
claim: FM29 prompt assertion impossible against Socialite::fake
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: socialite Testing/FakeProvider.php:61-63,99-101
disposition: confirmed
basis: "FakeProvider::redirect returns a constant URL; with() forwarded to the real provider"
change: Unfaked redirect test asserting Location query
```

```yaml
id: PA-R7
claim: FM17 test variants cannot reach the retry branch
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 05-plan P2 tests
disposition: confirmed
basis: "A competitor inserted inside the same transaction is rolled back with it; a seeded conflict is found by lookup"
change: Competitor inserted via DB::listen before the transaction; negative control
```

```yaml
id: PA-R8
claim: FM28 lock-order test cannot observe FOR UPDATE on SQLite
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: SQLiteGrammar.php:31-34
disposition: confirmed
basis: "SQLiteGrammar compileLock returns an empty string"
change: pgsql test group run against the Sail testing DB
```

```yaml
id: PA-S1
claim: LastSpot writes lack household_id, household_name and saved_at, so retrieved marking and the banner card never work
kind: defect
severity: P1
reviewers: [opus, sonnet, agy]
evidence: 05-plan P5 presenter vs P6 LastSpot
disposition: confirmed
basis: "Presenter fields did not include them; spread wrote undefined"
change: Presenter adds household fields; explicit fromSpot/fromCapture mappers
```

```yaml
id: PA-S2
claim: auth.user is null on the login page but typed non-null
kind: defect
severity: P3
reviewers: [sonnet]
evidence: resources/js/types/auth.ts
disposition: confirmed
basis: "share() returns null for guests"
change: Auth user typed User or null
```

```yaml
id: PA-S3
claim: households shared prop serialises pivot objects
kind: defect
severity: P3
reviewers: [opus, sonnet]
evidence: HandleInertiaRequests share
disposition: confirmed
basis: "BelongsToMany get() appends pivot attributes"
change: Explicit map to id and name
```

```yaml
id: PA-S4
claim: Leaflet circle called without latlng and with a string radius
kind: defect
severity: P2
reviewers: [opus, agy]
evidence: 05-plan P5 SpotMap
disposition: confirmed
basis: "L.circle requires latlng first; radius typed number"
change: Explicit tuple and Number() conversion
```

```yaml
id: PA-S4b
claim: generated_at missing from the shared-props TS type
kind: defect
severity: P3
reviewers: [sonnet]
evidence: resources/js/types/global.d.ts
disposition: confirmed
basis: "Falls through to unknown index signature"
change: Type added
```

```yaml
id: PA-S5
claim: A missing household_id on an Inertia PUT yields 302 with errors, not 422
kind: defect
severity: P3
reviewers: [opus]
evidence: 05-plan P4 tests
disposition: confirmed
basis: "Form request validation redirects back for non-JSON requests"
change: assertSessionHasErrors
```

```yaml
id: PA-S6
claim: Flash payload must be keyed toast
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: lib/flashToast.ts; types/ui.ts:6-9
disposition: confirmed
basis: "Kit listens on flash.toast"
change: Global flash convention
```

```yaml
id: PA-D1
claim: HouseholdPolicy parkIn never called
kind: defect
severity: P3
reviewers: [opus, sonnet, agy]
evidence: 05-plan P5 store
disposition: confirmed
basis: "Store used isMemberOf directly; request authorize returned true"
change: Gate authorize parkIn under the lock is the single gate
```

```yaml
id: PA-D2
claim: HouseholdPolicy view never called
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: 05-plan P4
disposition: confirmed
basis: "Household page renders currentHousehold only"
change: view ability removed
```

```yaml
id: PA-D3
claim: Precache serves all build assets so the assets runtime cache is dead and FM13 old chunks are not kept
kind: defect
severity: P2
reviewers: [opus]
evidence: 05-plan P6 sw.ts
disposition: confirmed
basis: "precacheAndRoute registered first covers every glob-matched build asset"
change: assets rule removed; activate deletes pages and inertia caches; offline fallback covers
```

```yaml
id: PA-FM18
claim: FM18 negative control cannot fail because an early membership check answers first
kind: defect
severity: P1
reviewers: [opus, sonnet]
evidence: 05-plan P5 store
disposition: confirmed
basis: "call-path: plan store abort_unless isMemberOf before storing the photo and before the lock"
change: Early check removed; under-lock gate is the only one
```

```yaml
id: PA-I1
claim: Member removal checks membership before authorizing, a membership oracle; exists rules leak household existence
kind: defect
severity: P2
reviewers: [opus, sonnet]
evidence: 05-plan P4 destroy
disposition: confirmed
basis: "call-path: plan abort_unless member 404 ran before authorize removeMember"
change: Authorize first; no exists rules; null household denied 403
```

```yaml
id: PA-I1b
claim: Scoped invite binding returns 404 before the owner check, revealing invite-id existence within a household
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: 05-plan P4 routes
disposition: confirmed
basis: "SubstituteBindings runs before controller authorization"
change: Accepted residual; ids reveal nothing else
```

```yaml
id: PA-I3
claim: Memo on the model instance is reused across requests in tests via actingAs
kind: improvement
severity: P3
reviewers: [opus]
evidence: 05-plan P2 CurrentHouseholdTest
disposition: confirmed
basis: "actingAs stores the instance"
change: Tests use actingAs with fresh()
```

```yaml
id: PA-limiter
claim: Design requires an OAuth limiter 429 test that the plan omitted
kind: defect
severity: P3
reviewers: [sonnet]
evidence: 03-design section 10
disposition: confirmed
basis: "Plan P2 test table had no limiter test"
change: OAuthLimiterTest
```

```yaml
id: PA-agy-S2
claim: is_owner is not a column, so members list never shows the owner badge
kind: defect
severity: P3
reviewers: [agy]
evidence: 05-plan P4 HouseholdController
disposition: confirmed
basis: "Ownership lives on households.owner_id"
change: Explicit member map
```

```yaml
id: PA-agy-R1
claim: HomeController calls an undefined global currentHousehold()
kind: defect
severity: P2
reviewers: [agy]
evidence: 05-plan P5 HomeController
disposition: confirmed
basis: "currentHousehold is a User method"
change: Call via request user
```

```yaml
id: PA-agy-R3
claim: Unsaved prompt condition older-than-generated_at hides it in the same session
kind: defect
severity: P3
reviewers: [agy]
evidence: 05-plan P6 UnsavedSpotPrompt
disposition: confirmed
basis: "An unsaved write after page render has saved_at newer than generated_at"
change: Shown whenever unsaved and no request in flight
```

```yaml
id: PA-dead
claim: Dead or stale items - alreadyMember prop, Socialite guzzle fallback, Welcome layout case, leftover TwoFactorAuthenticationRequest, kit ExampleTest
kind: improvement
severity: P3
reviewers: [opus, sonnet]
evidence: 05-plan P2 and P4
disposition: confirmed
basis: "Unused prop; SocialiteManager.php:253 passes guzzle; files remain after chisel"
change: Removed or listed for deletion
```

```yaml
id: PA-lock
claim: Claim that npm install re-syncs a lockfile is wrong - kit ships none
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: kit root
disposition: confirmed
basis: "Kit has composer.lock and pnpm-workspace but no package-lock.json"
change: Text corrected; lock committed
```

```yaml
id: PA-ports
claim: Sail publishes 5432, 1025, 8025 which may collide; only pgsql has a healthcheck
kind: improvement
severity: P3
reviewers: [sonnet]
evidence: sail stubs
disposition: confirmed
basis: "Stub port mappings and healthchecks"
change: Port check step; verification wording fixed
```

```yaml
id: PA-O11
claim: Invite cancel skips a post-lock ownership re-check
kind: improvement
severity: P3
reviewers: [opus]
evidence: 05-plan P4 destroy
disposition: confirmed
basis: "Ownership cannot change in this app"
change: No change
```

