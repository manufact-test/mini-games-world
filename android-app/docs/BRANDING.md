# Android Branding Pack V1

## Scope

This branding pack is intentionally limited to Android platform chrome inside `android-app/**`.
It does not redesign or duplicate the existing Mini Games World web UI.

## Selected identity

**Shield King / MGW** is the approved Android brand direction.

The FINAL approved launcher artwork is the centered square composition with:

- near-black rounded-square base;
- thin restrained violet perimeter accent;
- centered premium shield;
- centered violet/gold crown;
- large centered metallic `MGW` lettering;
- deep violet / black surfaces;
- no separate plate, halo or offset shape behind the shield.

The earlier flat purple `MG` tile and the earlier simplified shield-only Android variants are deprecated and MUST NOT be reused.

## Critical composition rule

The shield/crown/`MGW` composition must be optically centered on both axes.

Forbidden behind the shield/mark:

- white or silver offset shadow;
- pale backplate;
- duplicated shield silhouette;
- asymmetric glow blob;
- right/left shifted highlight used as a background object;
- decorative object that makes the launcher mark appear off-center.

Normal metallic highlights ON the shield/crown/letters are allowed. A separate visible light shape BEHIND the mark is not.

The canonical raster source is `android-app/branding/shield_king_launcher_384.webp.b64` as updated after product-owner acceptance on 2026-08-09. Future icon builds must use this artwork rather than reconstructing it as a simplified VectorDrawable.

## Approved palette

- launcher background: `#0C0F14`
- platform startup background: `#080B12`
- dark brand surface: `#17121F`
- inner violet surface: `#231942`
- MGW purple: `#6A4CFF`
- highlight violet: `#A65FF7`
- crown gold: `#FFD45C`
- silver highlight: `#E6E8EF`
- white: `#FFFFFF`

This palette deliberately replaces the earlier brighter generic-purple treatment.

## Launcher

- adaptive icon for API 26+;
- round adaptive icon;
- monochrome/themed icon for API 33+;
- one symmetric inset owner on all four sides;
- no device-specific left/right padding patch;
- approved raster remains the visual source of truth;
- launcher background around an adaptive crop must stay near-black and visually disappear into the artwork;
- icon must remain visually centered under OEM masks.

## Platform splash / startup

Android's required platform startup window must NOT display a second large Shield King/MGW logo before the real MGW loading surface.

Required behavior:

- platform splash is only a short near-black transition surface;
- central platform splash icon is transparent/non-visible where platform contracts allow;
- no artificial delay;
- no duplicate branded loading screen;
- the existing/shared MGW loading/preparation UI remains the product loading owner.

## Acceptance install identity

Manual Android acceptance uses the stable test-only package:

`com.minigamesworld.app.acceptance`

It uses a stable acceptance-only signing certificate so verified APKs can update one another reliably. This package/signing key is NOT the future production identity.

## Ownership

All persistent branding changes live inside `android-app/**`.
No backend, Telegram, DB, economy, matchmaking, shared production CI, staging, main or production owner is modified by this pack.

## Future product redesign

The broader Shield King full-app visual direction is approved conceptually and recorded in `FULL_APP_REDESIGN_HANDOFF.md`.
The same no-offset-backplate/no-white-shadow rule applies to all future uses of the primary mark.

The shared web/Telegram UI must be redesigned through the main MGW roadmap, never by creating an Android-only visual fork.
