---
date: 2026-10-27T20:39:58+0200
researcher: liakosbeupset
git_commit: f2f3f3dd897e987065cddbe8f2374267076a473c
branch: main
repository: snuggy-parking
topic: "Greenfield Snuggy Parking PWA — scaffold, auth, PWA, map and Docker contracts"
tags: [research, laravel-vue-starter-kit, fortify, socialite, vite-plugin-pwa, leaflet, sail]
status: complete
last_updated: 2026-10-27
last_updated_by: liakosbeupset
---

# Research: Snuggy Parking PWA foundations

**Date**: 2026-10-27 | **Researcher**: liakosbeupset | **Commit**: f2f3f3d | **Branch**: main | **Repository**: snuggy-parking

## Research Question

The repo is empty (root commit holds only `.gitignore` and `README.md`). This document maps the foundations the app will stand on and where each requirement in `01-questions.md` lands:

- the current Laravel Vue starter kit's auth, frontend, test and tooling stack;
- Socialite;
- `vite-plugin-pwa` under the kit's build toolchain;
- Leaflet/OSM, Google Maps URLs, Geolocation;
- Sail/Docker.

**Reference scaffold.** `composer create-project laravel/vue-starter-kit ref dev-main` was run in the session scratchpad. Every `file:line` below without a URL is relative to that scaffold. It is the exact tree the implementation will start from.

Note: the Packagist-tagged kit (v1.0.2) is stale (Tailwind 3, Inertia 2 beta, Laravel 12). `dev-main` is the current kit: Laravel 13.

## Summary

- **Stack versions.** The current kit is Laravel `^13.17`, PHP `^8.3`, Inertia 3 (`inertiajs/inertia-laravel ^3.0`, `@inertiajs/vue3 ^3`), Vue 3.5, TypeScript, Tailwind 4, shadcn-vue (reka-ui), Wayfinder, Fortify `^1.37.2` and laravel/chisel (composer.json:11-19).
- **Build tooling.** The build runs on **Vite+**: `vite` is aliased to `npm:@voidzero-dev/vite-plus-core@1.4.0` (package.json:36, :49-51), and scripts call `vp` (package.json:5-11).
- **Tests.** Tests are **PHPUnit 12** class-style, not Pest (composer.json:29). There is no `tests/Pest.php`.
- **Auth.** Auth is entirely Fortify. It provides login, registration, password reset, email verification, 2FA, passkeys and password confirmation (config/fortify.php:169-189). Password change and account delete in settings also depend on passwords. There is no Socialite.
- **Feature stripping.** `php artisan install:features` (app/Console/Commands/InstallFeaturesCommand.php:36-67, driven by chisel.php:88-100) can strip registration, email verification, 2FA, passkeys and password confirmation. **Password login itself is not one of its options.**
- **PWA spike.** `vite-plugin-pwa@2.0.0` installs and builds under the Vite+ alias. It emits `public/build/sw.js` plus `manifest.webmanifest` (spike output, see §PWA). The service worker sits at `/build/`, so by default it cannot control `/`.
- **Serving the SW.** Sail serves with `artisan serve`, PHP's built-in server (sail runtimes/8.4/Dockerfile:16). Static files therefore bypass Laravel and cannot gain a `Service-Worker-Allowed` header without a custom router.
- **Socialite.** Socialite v5.31 supports Laravel 13 and ships `Socialite::fake()` for tests. Google returns `email_verified` only in the raw user array.
- **Map and tiles.** Leaflet is at 1.9.4. The OSM tile policy forbids prefetching and "offline use". Caching viewed tiles while honouring headers is not named as prohibited.

## Detailed Findings

### Composer, bootstrap, providers
- composer.json:11-19 require: php ^8.3, inertia-laravel ^3.0, laravel/chisel ^0.1.0, laravel/fortify ^1.37.2, laravel/framework ^13.17, tinker, laravel/wayfinder ^0.1.14.
- composer.json:20-30 require-dev: faker, larastan/larastan ^3.9 (:22), pail, pao, laravel/pint ^1.27 (:25), laravel/sail ^1.53 (:26), mockery, collision, phpunit ^12.5.23 (:29). Pest appears only as an allowed plugin (composer.json:112).
- Composer scripts:
  - `test` (composer.json:71-76): `config:clear`, then `pint --test`, `phpstan analyse`, `artisan test`.
  - `lint`: `pint --parallel`.
  - `types:check`: phpstan.
  - `ci:check` (:62-67): `npm run check`, `npm run types:check`, `@test`.
  - `post-update-cmd` (:81) runs `artisan install:features`.
- bootstrap/app.php:10-14 routing: web, console, health `/up`. bootstrap/app.php:17-21 appends HandleAppearance, HandleInertiaRequests and AddLinkHeadersForPreloadedAssets to the web group.
- bootstrap/providers.php:5-6: AppServiceProvider, FortifyServiceProvider.

### Fortify / password auth surface (what Google-only login has to displace)
- **FortifyServiceProvider.**
  - Actions: app/Providers/FortifyServiceProvider.php:45 (`resetUserPasswordsUsing`), :47 (`createUsersUsing`).
  - Inertia views (:56-88): auth/Login, ResetPassword, ForgotPassword, VerifyEmail, Register, TwoFactorChallenge, ConfirmPassword.
  - Rate limiters: :98-114.
- **config/fortify.php.**
  - Home is `/dashboard` (:76); views true (:138).
  - Features enabled: registration :171, resetPasswords :173, emailVerification :175, 2FA :178-182, passkeys :185-187.
  - Fortify registers its own routes (login, logout, register, password.*, two-factor.*, verification.*, password.confirm, passkeys). None of them are declared in routes/web.php.
- **Settings routes.**
  - routes/web.php:5: `/` renders Welcome (`home`). :7-9: `dashboard` behind `auth`+`verified`. :11 requires settings.php.
  - routes/settings.php:10-15: profile edit/update.
  - :17-31, behind `auth`+`verified`:
    - profile.destroy
    - security.edit, behind `RequirePassword` (:22)
    - user-password.update
    - appearance.edit
  - :34-39: passkey well-known endpoint.
- **Controllers and requests.**
  - Settings/ProfileController: `destroy` requires the password via ProfileDeleteRequest.php:21.
  - Settings/SecurityController: password update, 2FA and passkeys.
  - PasswordUpdateRequest.php:21-22.
  - app/Concerns/PasswordValidationRules.php:15-25. ProfileValidationRules.php:13-45 has no password logic.
- **User model.**
  - app/Models/User.php:30-31: fillable name, email, password; hidden password, 2FA secrets, remember_token.
  - :32 implements `MustVerifyEmail, PasskeyUser`. :35 traits PasskeyAuthenticatable, TwoFactorAuthenticatable. Password cast `hashed` (:46).
- **Migrations.**
  - 0001_01_01_000000_create_users_table.php:14-21 has `password` as non-null string (users) and creates `password_reset_tokens` and `sessions`.
  - 2024_01_01_000000_create_passkeys_table.php creates passkeys.
  - 2025_08_14_170933_add_two_factor_columns_to_users_table.php adds the 2FA columns.
- **Factory and seeder.** UserFactory.php:30-36 sets password, email_verified_at and 2FA nulls; `withTwoFactor()` is at :55-60. DatabaseSeeder.php:16-24 creates test@example.com.
- **Frontend tied to passwords.**
  - Pages: auth/* (7 pages), settings/Security.vue.
  - Components: DeleteUser.vue, PasswordInput.vue, ManageTwoFactor.vue, TwoFactor*.vue, Passkey*.vue, ManagePasskeys.vue.
  - Composable: useTwoFactorAuth.ts.
  - Login.vue imports `@/routes/login`, `@/routes/password` and PasskeyVerify (Login.vue:14-18). Pages carry `@chisel-*` markers.
- **Tests.** Tests tied to passwords: tests/Feature/Auth/* (7 files) and tests/Feature/Settings/{ProfileUpdateTest, SecurityTest}. tests/TestCase.php:10-15 `skipUnlessFortifyHas()`.
- **Chisel.** `install:features` deselection: chisel.php:88-100 offers email-verification, registration, 2fa, passkeys and password-confirmation. Each `else:` closure (from :106) deletes that feature's files and npm packages. Paths are in chisel-paths.php. Login and password reset are not chisel options.

### Shared Inertia props, session, storage
- app/Http/Middleware/HandleInertiaRequests.php `share()`: `name`, `auth.user` (full `$request->user()` model minus hidden attributes), `sidebarOpen`.
- Frontend type is in types/global.d.ts:17-26 (`InertiaConfig.sharedPageProps`), and `User` is in types/auth.ts:1-16.
- config/session.php:21 driver `database` (table `sessions`, :89). config/auth.php:42-43, :66-67: session guard `web` with the eloquent User provider.
- config/filesystems.php:
  - Default disk `local` (:16).
  - `local` root is `storage_path('app/private')` (:35). It is not web-reachable.
  - `public` root is `storage/app/public` (:43), served through the `public/storage` symlink (:77).

### Frontend
- **vite.config.ts.**
  - :7 imports from `vite-plus`.
  - Plugins (:10-33): laravel (inputs `resources/css/app.css`, `resources/js/app.ts`; bunny font), `@inertiajs/vite`, tailwindcss, vue, wayfinder `formVariants:true`.
  - No `build` block, so output goes to `public/build` (.gitignore:3).
  - `lint` (:45-61) and `fmt` (:62-79) are oxlint and oxfmt style configs inside vite.config.ts. There is no eslint or prettier config file.
- **app.blade.php.**
  - resources/views/app.blade.php:39 `@vite([...app.css, app.ts, "resources/js/pages/{$page['component']}.vue"])`.
  - :33-35 favicon and apple-touch-icon links.
  - No manifest link, no theme-color, no SW registration. public/ holds favicon.ico, favicon.svg, apple-touch-icon.png, robots.txt.
- **app.ts.**
  - resources/js/app.ts:9 `createInertiaApp` with no `resolve`; page resolution comes from `@inertiajs/vite`.
  - :11-22 a layout callback: Welcome gets no layout, `auth/*` gets AuthLayout, `settings/*` gets `[AppLayout, SettingsLayout]`, everything else gets AppLayout.
  - :41 `initializeFlashToast()` (lib/flashToast.ts listens for the Inertia `flash` event and calls a vue-sonner toast).
  - config/inertia.php:18-22: SSR enabled, but there is no ssr.ts.
- **Wayfinder.**
  - Pages import named routes from `@/routes` and `@/routes/<group>`, e.g. AppSidebar.vue:17, Login.vue:14-15.
  - Controller actions come from `@/actions/App/Http/Controllers/...` (settings/Profile.vue:7).
  - The generated dirs are git-ignored (.gitignore:9-11) and generated by the vite plugin.
- **Forms.** Inertia 3 `<Form v-bind="X.form()" v-slot="{errors, processing}">` (Login.vue:47-52, Profile.vue:46-50). `useHttp` is used for non-navigation requests (useTwoFactorAuth.ts:1).
- **Layouts.** AppLayout wraps AppSidebarLayout (layouts/AppLayout.vue:1-14), with an AppHeaderLayout variant. AuthLayout wraps AuthSimpleLayout, with Card and Split variants.
- **Navigation.**
  - Nav items: `NavItem {title, href, icon?, isActive?}` (types/navigation.ts:10-15). AppSidebar.vue:20-26 `mainNavItems`.
  - NavMain.vue:24-32 renders Inertia `<Link>`s.
- **UI primitives.** Available in components/ui: alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, input, input-otp, label, navigation-menu, select, separator, sheet, sidebar, skeleton, sonner, spinner, tooltip.
- **Path alias.** tsconfig.json:56-58 maps `@/*` to `resources/js/*`.
- **.gitignore.** The kit's .gitignore also ignores `.claude`, `CLAUDE.md`, `AGENTS.md`, `.mcp.json` (frontend agent report).

### Testing and quality tooling
- **phpunit.xml.**
  - Suites Unit and Feature (:23-30).
  - Env overrides (:37-50): `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `BCRYPT_ROUNDS=4`.
- **Test style.** tests/Feature/DashboardTest.php:9-27 shows the style: PHPUnit class, `RefreshDatabase`, `actingAs`, `assertRedirect(route('login'))`. `assertInertia(fn (Assert $page) => …)` is used in Auth/PasswordConfirmationTest.php:22.
- **Pest.** Pest v4.4.0 requires `phpunit/phpunit ^12.5.12` (`composer show -a pestphp/pest v4.4.0`). The kit's phpunit ^12.5.23 satisfies it.
- **Pint and PHPStan.** pint.json:2 preset `laravel`. phpstan.neon: level 7 (:16), paths app/, bootstrap/app.php, config/, database/, routes/ (:9-14); tests/ is not analysed. Uses the larastan and carbon extensions (:4-6).
- **CI.** .github/workflows/tests.yml runs `composer setup && composer ci:check` on PHP 8.3 and Node 22.

### Socialite (external)
- **Version.** laravel/socialite v5.31.0 allows illuminate/support ^13.0 (`https://repo.packagist.org/p2/laravel/socialite.json`).
- **Config.** `config/services.php` key `google` with `client_id`, `client_secret` and `redirect`; a relative redirect is resolved (https://laravel.com/docs/13.x/socialite#configuration).
- **API.** `Socialite::driver('google')->redirect()` / `->user()`; `->stateless()` is available. Default scopes are openid, profile, email.
- **User object.** `getId()` (Google `sub`), `getEmail()`, `getName()`, `getAvatar()`.
- **email_verified.** It is only in the raw array: `$user->user['email_verified']` (https://raw.githubusercontent.com/laravel/socialite/5.x/src/Two/GoogleProvider.php).
- **Testing.** `Socialite::fake('google', \Laravel\Socialite\Two\User::fake([...]))` is documented (https://laravel.com/docs/13.x/socialite#testing).
- config/services.php:17-36 currently holds only postmark, resend, ses and slack.

### PWA (spike in reference scaffold, plus docs)
- **Packages.** vite-plugin-pwa 2.0.0 peer deps: `vite ^3.1 … ^7 || ^8`, workbox-build/window ^7.4.1 (`npm view vite-plugin-pwa peerDependencies`).
- **Spike result.** `npm install -D vite-plugin-pwa@2.0.0` completed under the Vite+ alias. Only EBADENGINE warnings for Node 22.12 were printed.
- **Spike config.** Added to the plugin list in vite.config.ts: `VitePWA({ outDir:'public/build', buildBase:'/build/', scope:'/', base:'/', injectRegister:null, manifest:{…start_url:'/', scope:'/', display:'standalone'}, workbox:{ navigateFallback:null, globPatterns:[…] } })`.
- **Spike output.**
  - `npm run build` (`vp build`) emitted `public/build/sw.js`, `public/build/workbox-*.js` and `public/build/manifest.webmanifest`, with `"start_url":"/"` and `"scope":"/"`. Precache was 38 entries (730 KiB) in generateSW mode.
  - **Default precache URLs are relative to the SW file** (`url:"assets/Welcome-….js"`). The SW loads its runtime via `define(["./workbox-…"])`.
  - With `workbox.inlineWorkboxRuntime:true` and `workbox.modifyURLPrefix:{'':'/build/'}`, the build emits a self-contained `sw.js`. It has 0 `importScripts(`/`./workbox` references, its precache URLs are absolute (`url:"/build/assets/Welcome-….js"`), and no separate workbox file is emitted.
- **Scope rule.** A SW's maximum scope is its own directory unless the response carries a `Service-Worker-Allowed` header. The official Laravel guide uses that header via a custom server.php (https://vite-pwa-org.netlify.app/frameworks/laravel). It also says the PWA works in production builds only.
- **How Sail serves.** Sail runs `php … artisan serve --host=0.0.0.0 --port=80` (vendor/laravel/sail/runtimes/8.4/Dockerfile:16; supervisord.conf:8). Existing files under public/ are served directly by PHP's built-in server without going through Laravel middleware.
- **Installability.** Chrome needs HTTPS (or localhost), `name`/`short_name`, 192 and 512 px icons, `start_url`, and `display` standalone/minimal-ui (https://web.dev/articles/install-criteria). The fetch-handler requirement was dropped for menu install in Chrome 108/112, but the automatic `beforeinstallprompt` still listed it (https://developer.chrome.com/blog/update-install-criteria).

### Leaflet / OSM / Google Maps / Geolocation (external)
- **Leaflet.** 1.9.4 is latest; `@types/leaflet` 1.9.22. Both install alongside the kit (spike). CSS is at `leaflet/dist/leaflet.css`. The default marker icon path is broken under bundlers; the common fix imports the `leaflet/dist/images/marker-*.png` files and calls `L.Icon.Default.mergeOptions`, or uses a custom/div icon (not verified this run).
- **OSM tile policy** (https://operations.osmfoundation.org/policies/tiles/):
  - Visible attribution is required.
  - Honour cache headers, or a 7-day minimum TTL.
  - Prefetching or bulk download of tiles the user is not viewing is prohibited.
  - "Offline use is not permitted"; "download for offline" is named as prohibited.
  - Do not send no-cache headers.
  - Tile URL: `https://tile.openstreetmap.org/{z}/{x}/{y}.png`.
- **Google Maps URLs.** `https://www.google.com/maps/dir/?api=1&destination=LAT%2CLNG` with optional `travelmode` (driving, walking, bicycling, two-wheeler, transit). Opens the Maps app on Android/iOS (https://developers.google.com/maps/documentation/urls/get-started).
- **Geolocation.** `getCurrentPosition(success, error, {enableHighAccuracy, timeout, maximumAge})`. Error codes: 1 PERMISSION_DENIED, 2 POSITION_UNAVAILABLE, 3 TIMEOUT. Requires a secure context; localhost counts, a plain-http LAN IP does not (https://developer.mozilla.org/en-US/docs/Web/API/Geolocation/getCurrentPosition).

### Docker / Sail
- **Runtimes.** vendor/laravel/sail/runtimes has 8.0–8.5; the 8.4 runtime is Ubuntu 24.04 with Node 24 and postgresql-client-18 (runtimes/8.4/Dockerfile:1, :6, :8, :73).
- **Stubs.** pgsql, mailpit, redis and others are in vendor/laravel/sail/stubs.
- **pgsql service.** pgsql.stub: `postgres:18-alpine`, port `${FORWARD_DB_PORT:-5432}`, volume `sail-pgsql`, init script that creates a `testing` database, `pg_isready` healthcheck. Service names: `laravel.test`, `pgsql`, `mailpit` (SMTP 1025, UI 8025). App on `${APP_PORT:-80}`, Vite on `${VITE_PORT:-5173}` (https://laravel.com/docs/13.x/sail).
- **Windows.** Sail officially supports Windows via WSL2. Bind mounts from `C:\` work but are slow. A CRLF in the shell scripts breaks them, so a `.gitattributes` with `eol=lf` is relevant (not verified this run).
- **Local environment.** Docker server 28.3.3 and PHP 8.4.11 (this machine).
- **.env.example defaults.** `APP_URL=http://localhost:8000`, `DB_CONNECTION=sqlite`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `MAIL_MAILER=log`, `FILESYSTEM_DISK=local`.

## Where each requirement lands

| Req / AC | Lands in (existing) | Observation |
|---|---|---|
| AC1 Google-only login | config/fortify.php:169-189; FortifyServiceProvider.php:45-88; routes/settings.php:17-31; auth pages; chisel.php:88-100 | Fortify owns `/login` and all password routes; chisel can remove 5 features, not login/reset |
| AC2 first login creates user + household | app/Models/User.php:30-46; users migration :14-21 (`password` non-null) | No google_id/avatar columns; password column required |
| AC3–AC5 spots | none (new) | Default disk `local` = storage/app/private (filesystems.php:35) |
| AC6 map + directions | none; Leaflet 1.9.4; Google Maps URL format | Per-page Vite entries (app.blade.php:39) |
| AC7–AC9, AC12 authorization | no policies exist; HandleInertiaRequests shares full user model | `auth.user` serialisation exposes whatever is not `$hidden` |
| AC10–AC11 invites | none; MAIL_MAILER=log; mailpit stub | — |
| AC13 PWA | app.blade.php (no manifest link); public/ assets; spike output | SW lands in /build/ |
| AC14 guest redirect | `auth` middleware usage routes/web.php:7-9 | `verified` middleware also applied today |
| AC15 tests + pint | phpunit.xml:37-50; composer.json:71-76 | No Pest yet; `composer test` also runs phpstan |

## Code References
- `composer.json:11-30` — dependencies; `:71-76` — test script
- `config/fortify.php:169-189` — enabled Fortify features
- `app/Providers/FortifyServiceProvider.php:45-114` — actions, views, limiters
- `routes/web.php:5-11`, `routes/settings.php:10-39` — app routes
- `app/Models/User.php:30-46` — fillable/hidden/interfaces/traits/casts
- `database/migrations/0001_01_01_000000_create_users_table.php:14-36` — users, password_reset_tokens, sessions
- `app/Http/Middleware/HandleInertiaRequests.php` — shared props
- `config/filesystems.php:16-44,77` — disks
- `resources/views/app.blade.php:33-45` — head, @vite, inertia root
- `resources/js/app.ts:9-41` — Inertia app, layout callback
- `vite.config.ts:7-79` — plugins, lint, fmt
- `chisel.php:88-106`, `app/Console/Commands/InstallFeaturesCommand.php:36-67` — feature stripping
- `vendor/laravel/sail/runtimes/8.4/Dockerfile:16` — `artisan serve` as the web server
- `vendor/laravel/sail/stubs/pgsql.stub` — postgres service

## Architecture Documentation
- Inertia 3 + Wayfinder: typed route/action helpers generated at build time and git-ignored; forms via `<Form v-bind="action.form()">`.
- Layout chosen centrally by page-name prefix in app.ts.
- Per-page Vite entries, so each page's chunk is a build entry.
- Quality gate bundled in `composer test` (pint, phpstan level 7, tests). Lint/format for the frontend lives in vite.config.ts (oxlint/oxfmt via `vp check`).

## Historical Context (from thoughts/)
- None. This is the first task in the repo.

## Related Research
- None.

## Open Questions
- Whether `vp check` (oxlint, `typeAware`, `denyWarnings`) passes with Leaflet types and the PWA virtual modules (`virtual:pwa-register`). Not exercised in the spike.
- Whether the `beforeinstallprompt` fetch-handler requirement still applies in current Chrome. Unconfirmed; a SW with a fetch handler sidesteps it either way.
- Exact OSM position on service-worker caching of tiles the user viewed. Not named in the policy.
- Bind-mount performance of `C:\other\snuggy-parking` into Docker Desktop for `vp dev` file watching. Runtime-only.
