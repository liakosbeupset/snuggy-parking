# Adversarial Audit: Implementation Plan (`05-plan.md`)

**Target Plan**: [`thoughts/tasks/snuggy-parking-pwa/05-plan.md`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md)  
**Context & Invariants**: [`thoughts/tasks/snuggy-parking-pwa/03-design.md`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md)  
**Audit Scope**: Reachability, shape consistency, cross-cutting branch flow, uniqueness assertions, helper reuse, and mitigation coverage for all active invariants and failure modes (`I1–I11`, `FM1–FM29`).

---

## Summary

The implementation plan in [`05-plan.md`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md) is thoroughly detailed and closely follows the architecture in [`03-design.md`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md). However, adversarial scrutiny against Laravel 13, Inertia 3, Vue 3, Leaflet, and Workbox runtime contracts identified several concrete defects:

1. **Dead Code / Unreachable Policy**: `HouseholdPolicy::parkIn` is defined and explicitly claimed in Phase P4 ([`05-plan.md:504`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L504)), but is bypassed in P5 in favor of direct ad-hoc calls to `$user->isMemberOf()`, leaving the policy method completely dead.
2. **Critical Shape Mismatch in Device Storage**: `SpotData` emitted by controllers omits `household_name` and `saved_at`, yet `useLastSpot` requires both. This causes `undefined` / `NaN` property comparisons in client code, breaking retrieved-spot clearing and suppressing the "Last spot you saw" fallback card ([FM24](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L523-L525)).
3. **Runtime Call Failures**: 
   - `HomeController@__invoke` calls `$h = currentHousehold()` as a global function ([`05-plan.md:695`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L695)) instead of calling `$request->user()->currentHousehold()`.
   - `SpotMap.vue` calls `L.circle(radius: accuracy)` ([`05-plan.md:743`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L743)) omitting coordinates, which throws a Leaflet `TypeError`.
4. **Broken Native Logout POST (HTTP 419)**: The plan switches logout to a native `<form method="post">` expecting `_token` in `usePage().props` or `<meta name="csrf-token">` ([`05-plan.md:858`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L858)), but neither is provided in `HandleInertiaRequests` nor `app.blade.php`.

---

## Reachability Issues

### 1. Undefined standalone function call `currentHousehold()` in `HomeController`
- **File & Line**: [`05-plan.md:695`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L695)
- **Trace**:
  `05-plan.md:695` defines:
  ```php
  HomeController@__invoke(Request). $h = currentHousehold(); renders Home with: ...
  ```
- **Issue**: `currentHousehold()` is defined in Phase P2 ([`05-plan.md:202`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L202)) as an instance method on `App\Models\User` (`public function currentHousehold(): Household;`). There is no global helper or facade function named `currentHousehold()`. Calling `currentHousehold()` in `HomeController` throws a fatal `Error: Call to undefined function currentHousehold()`.
- **Remediation**: Call `$h = $request->user()->currentHousehold();` as done in `HouseholdController@show` ([`05-plan.md:524`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L524)).

### 2. Invalid Leaflet call signature for `L.circle` in `SpotMap.vue`
- **File & Line**: [`05-plan.md:743`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L743)
- **Trace**:
  `05-plan.md:743` specifies:
  ```ts
  markers: L.marker + L.circle(radius: accuracy);
  ```
- **Issue**: In Leaflet, the signature for creating a circle is `L.circle(latlng, options)` or `L.circle(latlng, radius, options)`. Passing an options object as the first parameter without `latlng` causes Leaflet's constructor (`L.Circle`) to attempt accessing `latlng.lat` on `undefined`, throwing `TypeError: Cannot read properties of undefined (reading 'lat')`.
- **Remediation**: Pass coordinates explicitly: `L.circle([Number(lat), Number(lng)], { radius: Number(accuracy) }).addTo(map)`.

### 3. Reachability gate on `UnsavedSpotPrompt.vue` suppresses prompt on immediate failure
- **File & Line**: [`05-plan.md:871`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L871)
- **Trace**:
  `05-plan.md:871` defines the display condition for the unsaved spot prompt:
  ```ts
  shown on Home when the stored entry for the user is unsaved and older than the page's generated_at;
  ```
- **Issue**: If a user is on `Home` (rendered with `generated_at = T0`) and submits a spot at `T1 > T0` that fails (network timeout or expired session), `writeLastSpot` stores the capture with `saved_at = T1`. If the page is not reloaded from the server, `saved_at` (`T1`) is **newer** than `generated_at` (`T0`). Because the prompt requires the capture to be *older* than `generated_at`, it will never render during the current session, hiding the "Save now / Discard" options.
- **Remediation**: Display `UnsavedSpotPrompt` whenever `readLastSpot(user.id)?.status === 'unsaved'`, regardless of `generated_at`.

---

## Shape Mismatches

### 1. `SpotData` (Server Presenter) vs. `LastSpot` (`useLastSpot.ts`)
- **File & Line**: [`05-plan.md:700`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L700), [`05-plan.md:834-836`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L834-L836), [`05-plan.md:861`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L861), [`05-plan.md:863-864`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L863-L864), [`05-plan.md:880`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L880)
- **Trace**:
  1. `SpotData::from($spot)` ([`05-plan.md:700`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L700)):
     ```php
     {id, lat, lng, accuracy, note, has_photo, photo_url: has_photo ? route('spots.photo', id) : null, parked_at (ISO), retrieved_at (ISO|null), parked_by (name|null), directions_url, is_active}
     ```
  2. `LastSpot` TypeScript type ([`05-plan.md:834`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L834)):
     ```ts
     export type LastSpot = { id: number | null; household_name: string; lat: string; lng: string; accuracy: string; note: string | null; parked_at: string; parked_by: string | null; directions_url: string; status: 'saved' | 'unsaved' | 'retrieved'; saved_at: string };
     ```
  3. `Spots/Show.vue` calls `writeLastSpot` ([`05-plan.md:861`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L861)):
     ```ts
     writeLastSpot(user.id, {...spot, status: spot.is_active ? 'saved' : 'retrieved'})
     ```
- **Issue**:
  - `SpotData` does **not** include `household_name` (`household` is a separate prop `{id, name}`).
  - `SpotData` does **not** include `saved_at`.
  - Spreading `...spot` writes `household_name: undefined` and `saved_at: undefined` into `localStorage`.
  - In `Home.vue` ([`05-plan.md:864`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L864)), the check `else, if the stored spot belongs to household.name and is saved: markRetrieved(user.id)` evaluates `undefined === household.name` (always `false`), so retrieved cars are never updated locally.
  - In `SavedCopyBanner.vue` ([`05-plan.md:880`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L880)), checking whether `readLastSpot is newer than generated_at` computes `Date.parse(undefined) > Date.parse(generated_at)` (`NaN > ...`), which is always `false`. The "Last spot you saw" fallback card is never displayed.
- **Remediation**: Include `household_name: string` in `SpotData::from($spot)` or pass `household.name` and `new Date().toISOString()` explicitly in `Spots/Show.vue` and `Home.vue`.

### 2. Missing `is_owner` attribute in `HouseholdController@show` members collection
- **File & Line**: [`05-plan.md:526`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L526), [`05-plan.md:592`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L592)
- **Trace**:
  `05-plan.md:526` specifies `HouseholdController@show` props:
  ```php
  members [{id, name, email, avatar, is_owner}], via $h->members()->orderBy('name')->get();
  ```
  And `Household.vue` ([`05-plan.md:592`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L592)):
  ```
  the member list, with an owner badge and a remove button (owner, not for self or the owner);
  ```
- **Issue**: Per KD2 ([`03-design.md:613`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/03-design.md#L613)) and the migrations in P2 ([`05-plan.md:178-191`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L178-L191)), household ownership is stored on `households.owner_id`, not in `household_user` or `users`. Calling `$h->members()->orderBy('name')->get()` returns Eloquent `User` models whose serialized JSON attributes contain no `is_owner` property. In `Household.vue`, `member.is_owner` will evaluate to `undefined`.
- **Remediation**: Explicitly map members in `HouseholdController@show`:
  ```php
  'members' => $h->members()->orderBy('name')->get()->map(fn (User $u) => [
      ...$u->only(['id', 'name', 'email', 'avatar']),
      'is_owner' => $u->id === $h->owner_id,
  ]),
  ```

### 3. Coordinate type mismatch: `useGeolocation.ts` (`number`) vs. `LastSpot` (`string`)
- **File & Line**: [`05-plan.md:725`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L725), [`05-plan.md:834`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L834), [`05-plan.md:866`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L866)
- **Trace**:
  1. `GeoResult` defines `lat: number; lng: number; accuracy: number;`.
  2. `LastSpot` defines `lat: string; lng: string; accuracy: string;`.
  3. `ParkSheet.vue` writes:
     ```ts
     writeLastSpot(user.id, {...captured, id: null, status: 'unsaved', saved_at: now});
     ```
- **Issue**: Spreading `captured` (which has `number` coordinates) directly into `writeLastSpot` triggers a TypeScript type error under strict checking (`npm run types:check`).
- **Remediation**: Define `lat: string | number; lng: string | number; accuracy: string | number;` on `LastSpot`, or format coordinates to strings before calling `writeLastSpot`.

---

## Dead Branches

### 1. `HouseholdPolicy::parkIn` is unreachable and never evaluated
- **File & Line**: [`05-plan.md:504`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L504) vs. [`05-plan.md:692, 703, 709`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L692)
- **Trace**:
  - In Phase P4 ([`05-plan.md:504`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L504)):
    ```php
    - parkIn: member (consumed in P5).
    ```
  - In Phase P5 ([`05-plan.md:692`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L692)):
    `StoreParkingSpotRequest::authorize()` returns `true`.
  - In Phase P5 ([`05-plan.md:703, 709`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L703)):
    `ParkingSpotController@store` executes:
    ```php
    abort_unless($r->user()->isMemberOf($household), 403);
    // ...
    if (! $user->isMemberOf($household)) throw new AuthorizationException;
    ```
- **Issue**: Neither the FormRequest, the controller, nor any route middleware invokes `authorize('parkIn', $household)`. `HouseholdPolicy::parkIn` is dead code with no incoming call sites.
- **Remediation**: Call `$this->authorize('parkIn', $household)` (or `$r->user()->can('parkIn', $household)`) in `StoreParkingSpotRequest` or in `ParkingSpotController@store`.

### 2. Missing base controller `AuthorizesRequests` trait
- **File & Line**: [`05-plan.md:531, 541, 573, 581, 714, 715, 717`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L531)
- **Trace**:
  The plan calls `$this->authorize(...)` across multiple controllers:
  ```php
  $this->authorize('switchTo', $h); // CurrentHouseholdController
  $this->authorize('revokeInvite', $household); // HouseholdInviteController
  $this->authorize('removeMember', [$household, $user]); // HouseholdMemberController
  $this->authorize('view', $spot); // ParkingSpotController
  ```
- **Issue**: In Laravel 11, 12, and 13 starter kits, `App\Http\Controllers\Controller` is an abstract class without traits. The `Illuminate\Foundation\Auth\Access\AuthorizesRequests` trait is not included by default. Unless the base `Controller` class explicitly uses `AuthorizesRequests`, calling `$this->authorize()` on any controller throws `BadMethodCallException: Call to undefined method ...::authorize()`.
- **Remediation**: Explicitly state in Phase P1/P2 that `AuthorizesRequests` must be added to `app/Http/Controllers/Controller.php`, or use `Gate::authorize(...)` throughout.

---

## Failure Modes Not Ruled Out

### Verified Failure Modes and Invariants Matrix
| Id | Status in Plan | Audit Finding |
|---|---|---|
| **I1** | Covered ([`05-plan.md:498, 671, 717`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L498)) | Verified: `denyAsNotFound` and membership checks guard spot and household boundaries. |
| **I2** | Covered ([`05-plan.md:659, 710, 763`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L659)) | Verified: Partial unique index present and asserted in tests. |
| **I3** | Covered ([`05-plan.md:214-220, 578`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L214-L220)) | Verified: Self-heal persists and heals stale pointers; spot store ignores current household pointer. |
| **I4** | Covered ([`05-plan.md:181, 226, 501`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L181)) | Verified: Schema foreign key and policy guards prevent owner removal or abandonment. |
| **I5** | Covered ([`05-plan.md:564-569`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L564-L569)) | Verified: Single-use, expiry, and email match checked under transaction lock. |
| **I6** | Covered ([`05-plan.md:673, 716-719`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L673)) | Verified: Disk serve disabled; stream route enforces policy. |
| **I7** | Covered ([`05-plan.md:219, 233, 287`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L219)) | Verified: Guaranteed by login creation, factory hook, and self-heal. |
| **I8** | Covered ([`05-plan.md:163, 272, 282`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L163)) | Verified: Verified email enforced; email uneditable; conflicting `google_id` refused. |
| **I9** | Withdrawn | Verified: Withdrawn in design rev 4. |
| **I10** | Covered ([`05-plan.md:500, 546, 563`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L500)) | Verified: Cancel deletes row under lock; accept aborts 404 if deleted. |
| **I11** | Covered ([`05-plan.md:801, 853-858`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L801)) | Verified: Clearing logic handles logout, user change, and login page. |
| **FM1** | Covered ([`05-plan.md:708-711`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L708-L711)) | Verified: Serialized by household lock; index backstop. |
| **FM2** | Covered ([`05-plan.md:707, 712, 764`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L707)) | Verified: Photo deleted on transaction throw. |
| **FM3** | Covered ([`05-plan.md:705-706, 764`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L705-L706)) | Verified: False return throws before database interaction. |
| **FM4** | Covered ([`05-plan.md:272, 317`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L272)) | Verified: Null/missing/unverified `email_verified` refused. |
| **FM5** | Covered ([`05-plan.md:245, 270-271`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L245)) | Verified: Exception handling and timeouts redirect to login. |
| **FM6** | Covered ([`05-plan.md:508, 538, 611`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L508)) | Verified: Invite created first; mail timeout in config; warning flash on throw. |
| **FM7** | Covered ([`05-plan.md:191, 564, 612`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L191)) | Verified: Invite lock serializes concurrent accepts; unique pivot prevents duplicate membership. |
| **FM8** | Covered ([`05-plan.md:216, 578, 615`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L216)) | Verified: Current pointer cleared only on match; self-heals on subsequent request. |
| **FM9** | **NOT FULLY RULED OUT** ([`05-plan.md:858`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L858)) | **Gap**: Native logout `<form>` lacks CSRF token source, resulting in HTTP 419 instead of successful logout (see below). |
| **FM10** | Covered ([`05-plan.md:724-730, 749`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L724-L730)) | Verified: Error states handled per geolocation code. |
| **FM11** | Covered ([`05-plan.md:735, 743`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L735)) | Verified: Warning displayed when accuracy > 50 m. |
| **FM12** | Covered ([`05-plan.md:742-743`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L742-L743)) | Verified: Map container displays marker/circle without tile dependency. |
| **FM13** | Covered ([`05-plan.md:434, 826`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L434)) | Verified: Update toast and long-lived asset cache. |
| **FM14** | Covered ([`05-plan.md:691, 731`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L691)) | Verified: Canvas downscaling with fallback; validation bounds enforced. |
| **FM15** | Covered ([`05-plan.md:535, 567`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L535)) | Verified: Validation rejects existing members; `syncWithoutDetaching` avoids duplicate rows. |
| **FM16** | Covered ([`05-plan.md:283, 317`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L283)) | Verified: Conflict with non-null `google_id` refused. |
| **FM17** | Covered ([`05-plan.md:274, 318`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L274)) | Verified: Retry catches `UniqueConstraintViolationException` outside transaction. |
| **FM18** | Covered ([`05-plan.md:708-712, 772`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L708-L712)) | Verified: Under-lock check aborts 403 and deletes photo. |
| **FM19–FM22** | Withdrawn | Verified: Withdrawn in design rev 4. |
| **FM23** | Covered ([`05-plan.md:563, 614`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L563)) | Verified: Re-read under lock returns 404 if deleted. |
| **FM24** | **NOT FULLY RULED OUT** ([`05-plan.md:880`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L880)) | **Gap**: Stale Home fallback card relies on `saved_at`, which is omitted by `SpotData`, preventing the card from ever showing. |
| **FM25** | Covered ([`05-plan.md:821-823`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L821-L823)) | Verified: Navigation cache miss falls back to precached `/offline`. |
| **FM26** | Covered ([`05-plan.md:866-868`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L866-L868)) | Verified: Unsaved capture written before POST; retry supported. |
| **FM27** | **NOT FULLY RULED OUT** ([`05-plan.md:871`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L871)) | **Gap**: Unsaved prompt condition (`older than generated_at`) suppresses display when spot was captured on current page. |
| **FM28** | Covered ([`05-plan.md:543-544, 562-563, 618`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L543-L544)) | Verified: Both accept and cancel lock `Household` first, then `HouseholdInvite`. |
| **FM29** | Covered ([`05-plan.md:268, 854`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L268)) | Verified: `prompt=select_account` on redirect; cache cleared on user ID change. |

---

## Other

### 1. HTTP 419 CSRF Token Mismatch on native Logout `<form>`
- **File & Line**: [`05-plan.md:858`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L858)
- **Detail**:
  The plan specifies:
  ```ts
  The kit's UserMenuContent.vue logout Link becomes a <form method="post" :action="logout().url"> with the CSRF token (a hidden _token field from usePage().props or a meta tag).
  ```
  In Inertia applications, requests are sent via Axios/Fetch with headers automatically populated from the `XSRF-TOKEN` cookie. Inertia does **not** share `_token` in `usePage().props` by default, and the starter kit's `app.blade.php` does **not** include `<meta name="csrf-token">`.
  Because neither source exists in the plan's files ([`05-plan.md:291-301, 421-426`](file:///C:/other/snuggy-parking/thoughts/tasks/snuggy-parking-pwa/05-plan.md#L291-L301)), submitting a native `<form method="post">` without a valid `_token` payload causes Laravel's `VerifyCsrfToken` middleware to abort with HTTP 419 (Page Expired).
- **Remediation**: Add `<meta name="csrf-token" content="{{ csrf_token() }}">` to `resources/views/app.blade.php` in Phase P1/P3, or share `csrf_token` in `HandleInertiaRequests::share()`.
