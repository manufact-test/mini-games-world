# Android Branding Pack V1

## Scope

This branding pack is intentionally limited to Android platform chrome inside `android-app/**`.
It does not redesign or duplicate the existing Mini Games World web UI.

## Brand source

The current MGW product already uses a compact `MG` mark in the application header. The Android launcher/splash treatment reuses that identity rather than inventing a new brand.

## Palette

- launcher background: `#15172A`
- splash background: `#090D18`
- MGW purple: `#7548FF`
- mark text: `#FFFFFF`

## Launcher

- adaptive icon for API 26+
- round adaptive icon
- monochrome/themed icon for API 33+
- foreground keeps generous adaptive-mask safety margins

## Splash

- pre-Android-12 startup uses a centered MG mark on the dark MGW background
- Android 12+ uses the platform splash attributes
- no artificial delay is added
- this shell-level splash does not own or replace MGW in-app loading/match preparation

## Ownership

All branding resources live inside `android-app/**`.
No backend, Telegram, DB, economy, matchmaking, shared CI, staging, main or production owner is modified by this pack.
