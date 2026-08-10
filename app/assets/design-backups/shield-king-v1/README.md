# Shield King V1 visual fallback

This folder freezes the accepted first Shield King staging presentation before the V2 light/metallic visibility pass.

Source staging commit: `d7be536a1391409dfad5a16efcb510a4f84d50b3`.

Preserved files:
- `tokens-v1.css` — V1 semantic visual tokens;
- `startup-preloader-v1.css` — V1 app startup loader presentation;
- `phase-b-presentation-v1.css` — V1 visual-only Phase B overlay skin.

Runtime ownership is intentionally NOT duplicated here. Phase B state/timing/readiness remains owned by `app/assets/js/production-v110-acceptance-runtime.js`; restoring the full exact V1 application is always possible directly from source commit `d7be536a1391409dfad5a16efcb510a4f84d50b3`.

These backup files are inert and are not imported by the active application.
