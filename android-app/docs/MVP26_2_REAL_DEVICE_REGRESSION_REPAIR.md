# MVP-26.2 real-device regression repair

Manual real-device smoke rejected the first MVP-26.2 APK.

Confirmed regressions:
- accepted package `com.minigamesworld.app.acceptance` was replaced by a parallel `.mvp26dev` app;
- stable acceptance signing was replaced by runner debug signing;
- accepted Shield King raster launcher was replaced by a simplified vector;
- neutral platform splash was replaced by a second visible logo;
- native-to-shared loading handoff was reduced to an unbranded spinner;
- Telegram E2E did not exercise the real Android-cookie boot chain.

Branding source is restored byte-for-byte from
`agent/android-branding-pack@4f110277c85df4c77d9a66b794ff620812c16d2d`.

Approved raster SHA-256:
`f096f1f4821e514abf880e37e3346e46a60e00f97713170591991a0cfea5dc5e`.

Required visual chain:
`neutral Android system splash → approved Shield King native handoff → shared MGW animated web preloader → product`.

The MVP-26.2 Keystore device credential and provider-neutral server auth remain the Android authentication owner.
