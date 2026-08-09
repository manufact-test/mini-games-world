# Android Shell Foundation — security contract

The foundation follows a fail-closed WebView model.

- HTTPS base URL required.
- Android cleartext traffic disabled.
- mixed HTTP content blocked.
- WebView local file access disabled.
- WebView content-provider access disabled.
- no `addJavascriptInterface` privileged bridge.
- no universal/file-origin access bypass.
- SSL errors are cancelled, never bypassed.
- release WebView debugging is disabled through `BuildConfig.DEBUG` gating.
- arbitrary `intent://`, `javascript:`, `data:`, `file:` and `content:` top-level navigation is blocked.
- no secrets, tokens, production credentials, auth implementation or payment logic live in this module.

JavaScript and DOM storage are enabled because the existing MGW web product requires a browser runtime. This is bounded by the configured HTTPS-origin policy and the disabled local-file bridge surface.

The shell deliberately does not add a Telegram compatibility bridge. Faking Telegram identity would create a second authentication/session owner and violate the isolation contract.
