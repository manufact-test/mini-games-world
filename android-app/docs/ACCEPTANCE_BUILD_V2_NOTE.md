# Android Branding Acceptance Build V2

This checkpoint replaces the rejected brandingv1/brandingv2 acceptance packaging.

Required properties:

- package: `com.minigamesworld.app.acceptance`;
- fixed acceptance-only signing key;
- approved Shield King launcher raster stored under `android-app/branding/` as base64 source;
- adaptive launcher consumes that raster through a safe inset;
- visible native splash logo remains disabled;
- verifier must pass build, lint, signature, package, raster-byte, `adb install -r`, activity launch and process-alive gates before an APK may be handed to the product owner.

This is test/acceptance infrastructure only and does not define the future production signing or application id.
