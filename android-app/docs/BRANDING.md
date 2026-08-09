# Android Branding Pack V1

## Scope

This branding pack is intentionally limited to Android platform chrome inside `android-app/**`.
It does not redesign or duplicate the existing Mini Games World web UI.

## Selected identity

**Shield King** is the approved Android brand direction.

The launcher/splash mark is a compact dark shield with a premium crown, deep violet inner field, gold crown accents and a small game-board motif. The old flat purple `MG` tile is deprecated and must not be treated as final branding.

The mark intentionally avoids large lettering inside the icon so it remains readable through Android launcher masks and at small sizes. The application label continues to identify the product as Mini Games World.

## Approved palette

- launcher background: `#0C0F14`
- splash background: `#080B12`
- dark brand surface: `#17121F`
- inner violet surface: `#231942`
- MGW purple: `#6A4CFF`
- highlight violet: `#A65FF7`
- crown gold: `#FFD45C`
- silver highlight: `#E6E8EF`
- white: `#FFFFFF`

This palette deliberately replaces the earlier brighter `#7548FF` treatment, which did not match the approved darker MGW direction.

## Launcher

- adaptive icon for API 26+;
- round adaptive icon;
- monochrome/themed icon for API 33+;
- foreground keeps generous adaptive-mask safety margins;
- Shield King is the canonical Android launcher mark;
- no device-specific padding patch.

## Splash

- pre-Android-12 startup uses the Shield King mark on the approved dark background;
- Android 12+ uses the platform splash attributes;
- no artificial delay is added;
- shell splash does not own or replace MGW in-app loading/match preparation.

## Debug install identity

Manual branding acceptance uses a dedicated debug application id suffix:

`com.minigamesworld.app.brandingv1`

Reason: the earlier Foundation and Branding APKs were produced by separate ephemeral GitHub-hosted debug signing keys. Reusing the same `.foundation` debug package makes Android reject the newer APK as a signature-mismatched update. The dedicated branding debug package is an acceptance-only isolation measure and does not define the future production application id.

## Ownership

All persistent branding changes live inside `android-app/**`.
No backend, Telegram, DB, economy, matchmaking, shared CI, staging, main or production owner is modified by this pack.

## Future product redesign

The broader Shield King full-app visual direction is approved conceptually and is recorded separately in `FULL_APP_REDESIGN_HANDOFF.md`.
It is intentionally not implemented by this isolated Android Branding Pack because the existing web/Telegram UI remains shared product surface and must be redesigned through the main MGW roadmap, not by creating an Android-only fork.
