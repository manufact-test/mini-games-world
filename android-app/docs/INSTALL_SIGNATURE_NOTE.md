# Android debug install signature note

The earlier Foundation and Branding APKs were built on separate GitHub-hosted runners. Each runner generated its own ephemeral debug signing certificate while both debug APKs used the same package id suffix `.foundation`.

Android therefore treated the newer APK as an update signed by a different key and could reject or stall the install flow.

Verified certificate SHA-256 values:

- Foundation APK: `b7f8fd59c9f222a2251fd633a67934c0d57fa4f73c9e1b046bff4e393228e24`
- Earlier Branding APK: `4712180c9600091752e6f31d1bfff24928f55d4df21f888ffd1899124abae749`

The final Branding Pack manual-test build uses a dedicated debug application id suffix `.brandingv1`, so it installs as a separate test package rather than attempting to update the earlier Foundation test app.

This debug-only isolation does not define the future production package id or signing strategy.
