# 01 — Questions: Snuggy Parking PWA

User approved all proposed defaults on 2026-10-27 ("correct").

## Summary

Greenfield Laravel 12 + Inertia + Vue 3 (TypeScript) installable PWA named **Snuggy Parking**, for a household to share where its car is parked. Google login via Socialite is the only authentication. A user belongs to one or more households (current one selected), invites others by email + token link, and records a parking spot via the Geolocation API with optional note and photo. The spot screen shows a Leaflet/OSM map, the note, the photo and a "Get directions" link to Google Maps. Only household members may see a household's spots, photos and invites; Pest tests prove this. Runs in Docker (PHP 8.4, PostgreSQL, Mailpit, Vite).

## Requirements

### Auth
- Google OAuth via Laravel Socialite is the only login. Starter-kit password login, registration, password reset, email verification and password-change screens are removed.
- First Google login creates the User (name, email, google_id, avatar) and a personal household ("{first name}'s household") which becomes the current household.
- Subsequent logins match by google_id (fallback: email) and update name/avatar.

### Households
- Household ↔ User many-to-many (pivot `household_user` with `role`: owner|member).
- User has `current_household_id`; a switcher appears when the user belongs to more than one household.
- Owner may invite by email and remove members. Members may leave; the owner cannot leave.

### Invites
- Owner enters an email → `HouseholdInvite` with random token, expires in 7 days; a copyable link `/invites/{token}` is shown and an email is sent (Mailpit in dev).
- Accepting requires login; the logged-in Google account's email must match the invite email (case-insensitive). On acceptance the user joins as member, the household becomes current, and the invite is marked accepted (single use).
- Expired, already-accepted, or email-mismatched invites are refused with a clear message.

### Parking spots
- `ParkingSpot`: household_id, user_id (who parked), lat, lng, accuracy (m), note (nullable), photo_path (nullable), parked_at, retrieved_at (nullable).
- At most one active spot (retrieved_at null) per household; parking again retrieves the previous active spot.
- "Got the car" sets retrieved_at.
- Short history list of recent spots for the current household.

### Screens
- Home: big "I parked here" button → Geolocation API (high accuracy) → optional note + optional camera photo (client-side resized/compressed, server max 5 MB) → saved → spot screen. Geolocation denied/unavailable shows an error state.
- Spot screen: Leaflet map with OSM tiles and a marker (accuracy circle), note, photo, who parked + when, "Get directions" button → `https://www.google.com/maps/dir/?api=1&destination={lat},{lng}`, and "Got the car".
- Household screen: members, invite form, pending invites, leave/remove.

### PWA
- Web manifest (name "Snuggy Parking", icons, standalone display, theme colour) and a service worker via vite-plugin-pwa (Workbox): app shell precached; spot pages and OSM tiles cached network-first / with limits so the last seen spot is viewable with weak signal. Offline parking is out of scope.

### Authorization
- Only members of a household can view its spots, spot photos, members and invites, and can park/retrieve for it. Non-members get 403 (or 404) on every such route.
- Photos are on a private disk and streamed only through an authorized route.

## Constraints
- Laravel 13, PHP 8.4, Inertia 3, Vue 3 + TypeScript, Tailwind 4; Pest for tests.
  - Originally written as Laravel 12 / Inertia v2. The user approved the current starter kit's Laravel 13 / Inertia 3 stack at the design gate on 2026-10-27.
- Docker Compose (Sail-style): app (PHP 8.4), PostgreSQL, Mailpit, Vite. Tests run on in-memory SQLite.
- Google OAuth client credentials come from `.env` placeholders; the user creates the client (redirect `http://localhost/auth/google/callback`). Tests mock Socialite.
- Geolocation requires a secure context (HTTPS or localhost).
- Repo: local git, root commit `f2f3f3d`; push to `github.com/liakosbeupset/snuggy-parking` only when the user asks. `thoughts/` is committed.

## Out of Scope
- Offline parking / background sync, push notifications.
- Multiple cars per household.
- Production deployment, HTTPS tunnel for real-device testing (documented only).
- Native apps; any login other than Google.

## Success Criteria

- AC1: Login is only possible via Google; no password/registration routes exist (`/login` offers only "Continue with Google"; `/register`, `/forgot-password` return 404).
- AC2: First Google login creates a User and a personal household, sets it current, and logs the user in; a repeat login reuses the same User.
- AC3: "I parked here" stores a ParkingSpot with lat, lng, accuracy, optional note, optional photo, parked_at = now, for the user's current household.
- AC4: Creating a new spot retrieves the household's previous active spot, so at most one active spot exists per household.
- AC5: "Got the car" sets retrieved_at on the spot.
- AC6: The spot screen renders a Leaflet/OSM map with a marker at the spot, the note, and a "Get directions" link to `https://www.google.com/maps/dir/?api=1&destination={lat}%2C{lng}` (comma URL-encoded per the Google Maps URLs docs; wording revised at the design gate, F18).
- AC7: A non-member of a household receives 403/404 when viewing, retrieving or fetching the photo of that household's spot.
- AC8: A user cannot create a spot in, or switch to, a household they are not a member of.
- AC9: Only the owner can create invites and remove members; a non-member cannot view a household's members or invites.
- AC10: Accepting a valid invite whose email matches the logged-in user adds them as a member and makes the household current; the invite becomes single-use.
- AC11: Expired, already-accepted, or email-mismatched invites are rejected and do not grant membership.
- AC12: Spot photos are stored on a non-public disk and served only through an authorized route.
- AC13: The app serves a valid web manifest (name "Snuggy Parking", icons 192/512, display standalone) and registers a service worker, so it is installable.
- AC14: Guests are redirected to login from every app route.
- AC15: The full Pest suite and `pint --test` pass inside the Docker app container.
