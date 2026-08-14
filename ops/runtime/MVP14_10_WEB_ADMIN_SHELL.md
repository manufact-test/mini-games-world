# MVP-14.10 — Minimal secure Web Admin shell

Status: **CANDIDATE / READ-ONLY**

## Purpose

Provide the first browser-based administrator surface without creating a second authentication system, a second database/runtime owner, or a second mutation path.

## Canonical ownership

### Authentication

`AuthService` remains the only Telegram Mini App signature validator.

The web-admin endpoint calls `AuthService::getTelegramUserFromInitData()` so staging-test auth, browser-dev users, custom passwords and cookie sessions are not accepted. The endpoint also requires `auth_date` to be no older than 15 minutes.

Authorization remains owned by the existing `AdminService::isAdmin()` and existing private `admin_ids` configuration.

### Storage

`bot/admin-read.php` is deliberately mapped to the already accepted `api` DB-primary entrypoint context:

- staging: `StorageFactory` resolves `admin-read.php` as `api`;
- production: `ProductionPrimaryApplicationEntrypoints` resolves `bot/admin-read.php` as `api`.

The endpoint performs only `readOnly()` and does not invoke `transaction()` or API success hooks.

### Admin business view

The web shell does not duplicate dashboard calculations. It renders the existing:

- `AdminService::dashboard()`;
- `AdminService::systemCheck()`.

### Launch

`WebAppLaunchUrl` remains the URL owner and publishes `/app/admin.php?v=1`.

The existing Telegram admin keyboard receives one `🌐 Web Admin` Web App button only when the full main admin keyboard is present. Payment/action keyboards do not receive the button.

## Browser shell

`app/admin.php` is a noindex, no-store, CSP-protected shell. It contains no private configuration or credentials.

`app/assets/js/admin-shell.js`:

- uses current `Telegram.WebApp.initData`;
- sends it only to same-origin `bot/admin-read.php`;
- performs one initial load and manual refresh only;
- creates no polling loop;
- stores no auth material in localStorage, sessionStorage or cookies;
- renders server text with `textContent` only.

## Explicitly not included in MVP-14.10

- payment approval/rejection;
- balance or Gold mutations;
- shop-order mutations;
- user deletion/bans;
- maintenance/runtime switching;
- database migration controls;
- backup/restore controls;
- admin password login;
- persistent web-admin sessions;
- automatic polling;
- Cron changes.

Those capabilities require separately scoped authorization, audit and mutation contracts and must not be smuggled into this minimal shell.

## Acceptance

MVP-14.10 is closed when:

1. focused contract checks are green;
2. exact staging deployment is verified;
3. an existing Telegram administrator opens the panel through the new Web Admin button;
4. overview and system-check data load successfully;
5. a non-admin / unsigned browser path cannot obtain data;
6. no existing game, bot, economy or admin Telegram behavior regresses.
