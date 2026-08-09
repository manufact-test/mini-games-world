# Final Shield King rebuild checkpoint

Status: code complete; final verifier branch/build pending.

Current goals:

- build with the approved Shield King resources;
- use debug package suffix `.brandingv1` to avoid the prior ephemeral-debug-signing conflict;
- run verifier, unit tests, lint and assembleDebug;
- provide a fresh staging APK for real-device install acceptance;
- keep all persistent product changes inside `android-app/**`.
