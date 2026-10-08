# MVP-27.6 — Full RU/EN cross-platform QA

**Base:** staging only. **Gate:** automated tests + exact Hostinger staging E2E + real Telegram/Android manual product acceptance. No production/main, DB manual edits, paid providers, new Android identity, or frozen game-engine changes.

## Proven prior acceptances (do not reopen without a reproducible defect)
- MVP-27.2 Telegram Mini App RU/EN and Home/Store/Profile manual acceptance.
- MVP-27.3 bot + new prepared-share invitations RU/EN after PR #2157.
- MVP-27.4 native Android resource parity and native emulator error/download/reauth tests, PR #2158; Android v2616.
- MVP-27.5 canonical linked MGW-account locale, PR #2159.
- MVP-27.5.1 immediate language-sheet close and optimistic UI with persistent save / rollback, PR #2160, manually accepted 2026-10-09.

## Automation gate in this slice
- Compare every RU/EN player-catalog leaf and format placeholder. Reject accidental EN Cyrillic except deliberately unchanged legacy parser aliases.
- All eight game-rule titles and bilingual language availability through **actual** client createI18n runtime.
- Coin plural forms for RU/EN (1, 2, 5, 21), localized number/date/time, invalid/unsupported locale and absent-key behavior.
- Exact allowlist for 46 RU-only historical/admin/shop/payment/prize/response server strings. Do NOT treat this as evidence of runtime unreachability. Review it if any such copy is observed by a player.
- Re-run canonical account preference, immediate switching, Telegram bot/invite copy transport, localization infrastructure, native Android resource/owner/device checks.
- This workflow is a *focused static/runtime QA gate*, not a claim that on-device visual parity or eight real multiplayer games have been tested.

## Remaining release acceptance matrix — manual/end-to-end, not yet PASS

| Product surface | Telegram RU | Telegram EN | Android RU | Android EN |
|---|---|---|---|---|
| Initial Home/loading/settings; language switching + restart | Pending | Pending | Pending | Pending |
| Profile, all cosmetics, avatar/badges, account link and Wallet | Pending | Pending | Pending | Pending |
| Store, Bundles, owned/equipped items, purchases in coins | Pending | Pending | Pending | Pending |
| Eight game setup/rules/board/scores/results/leave/reconnect | Pending | Pending | Pending | Pending |
| Invites/waiting/prepared Telegram Share/new invite | Pending | Pending | Pending | Pending |
| Friends, Arena, season, tournaments, rewards | Pending | Pending | Pending | Pending |
| Support, reports, notifications, account data/ZIP | Pending | Pending | Pending | Pending |
| Offline/slow request/reauth/retry (native Android-owned UI as applicable) | N/A | N/A | Pending | Pending |
| Numbers/dates/plurals and narrow-screen wrapping | Pending | Pending | Pending | Pending |

### Player accounts and precedence
1. For linked accounts: select EN in Telegram, confirm Android Web interface EN at restart. Select RU on Android, reload Telegram and confirm RU; maintain exactly one MGW account owner.
2. A second unlinked MGW account must not inherit language. Failed preference save must roll back visual preview without leaving disabled controls.
3. Native Android OS RU/EN resource language remains separate from the cross-device Web account preference. In Telegram, Telegram-owned system buttons stay at Telegram client's system language.
4. A Telegram prepared message is immutable: create a **new** invite after changing locale.
5. Test Android device back/resume/keyboard/rotation and at least narrow 320px / 390px Web viewports without modifying accepted game geometry.

## Finish criteria
Only mark **MVP-27.6 CLOSED** after focused CI, staging E2E on the exact merged staging SHA, no release-blocking localization findings in active owners, and explicit manual QA PASS. Do not infer all-game/on-device acceptance from static contracts alone. Subsequent MVP-28 site/legal remains blocked until this gate is satisfied.
