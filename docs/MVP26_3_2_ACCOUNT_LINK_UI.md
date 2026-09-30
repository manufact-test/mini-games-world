# MVP-26.3.2 — Android account-link UI

Base staging: `796e61b6b91434d0030294d63c91571c542ac557`.

## User flow

The Profile account section shows **Привязать Telegram-аккаунт** only when:
- current authenticated provider is `android_device`;
- the canonical MGW profile does not already expose a Telegram identity.

The flow:
1. user opens the Profile row;
2. UI explains that the existing Telegram MGW profile is authoritative;
3. Android requests a one-time backend challenge;
4. the HTTPS `t.me` deep link is opened as a top-level external navigation, which the accepted Android shell already routes to the system;
5. user confirms in the Telegram bot;
6. on return/focus the WebView silently checks challenge state;
7. confirmed challenge finalizes through the existing MVP-26.3.1 backend;
8. the WebView reloads so every cached profile/economy/social projection is rebuilt under the linked target MGW account.

## Secret handling

Only `challenge_id` and the server expiry string are persisted in localStorage.

The raw one-time Telegram token / deep-link URL is **never persisted**. It exists only in memory for the current WebView process. If the process dies before Telegram claims the challenge, the UI creates a new challenge instead of recovering the raw token.

## Boundaries

No game, Store, rating, tournament or economy semantics change.
No native Android code change is required because the accepted NavigationPolicy already sends external HTTPS links to the system.
No main / production / production DB / production Cron changes.
