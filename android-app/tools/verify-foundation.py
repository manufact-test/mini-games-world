#!/usr/bin/env python3
from __future__ import annotations
import base64
import hashlib
import pathlib
import sys
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
APP = ROOT / "app"
errors: list[str] = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

def text(path: pathlib.Path) -> str:
    require(path.is_file(), f"missing file: {path.relative_to(ROOT)}")
    return path.read_text(encoding="utf-8") if path.is_file() else ""

build = text(APP / "build.gradle")
manifest_path = APP / "src/main/AndroidManifest.xml"
manifest = text(manifest_path)
main = text(APP / "src/main/java/com/minigamesworld/app/MainActivity.java")
policy = text(APP / "src/main/java/com/minigamesworld/app/NavigationPolicy.java")
shell = text(APP / "src/main/java/com/minigamesworld/app/ShellConfig.java")
credential = text(APP / "src/main/java/com/minigamesworld/app/DeviceCredentialStore.java")
network = text(APP / "src/main/res/xml/network_security_config.xml")
styles = text(APP / "src/main/res/values/styles.xml")
styles31 = text(APP / "src/main/res/values-v31/styles.xml")
launcher26 = text(APP / "src/main/res/mipmap-anydpi-v26/ic_launcher.xml")
launcher33 = text(APP / "src/main/res/mipmap-anydpi-v33/ic_launcher.xml")
inset = text(APP / "src/main/res/drawable/ic_mgw_launcher_art_inset.xml")
transparent_splash = text(APP / "src/main/res/drawable/ic_mgw_splash_transparent.xml")
raster_b64 = text(ROOT / "branding/shield_king_launcher_384.webp.b64")
keystore_b64 = text(ROOT / "branding/mgw-acceptance-stable.keystore.b64")

require("compileSdk 36" in build, "compileSdk must remain 36")
require("targetSdk 36" in build, "targetSdk must remain 36")
require("minSdk 26" in build, "minSdk must remain 26")
require("MGW_BASE_URL" in build, "MGW URL must remain build-configurable")
require("applicationId 'com.minigamesworld.app.acceptance'" in build, "accepted package identity must be restored")
require("applicationIdSuffix" not in build, "repair must not create a parallel .mvp26dev app")
require("versionCode 2604" in build, "orientation corrective versionCode must remain monotonic")
require("mgw-acceptance-stable.keystore" in build, "stable acceptance signing must be restored")
require("usesCleartextTraffic=\"false\"" in manifest, "cleartext traffic must be disabled")
require('android:configChanges="orientation|screenSize|smallestScreenSize|screenLayout|keyboardHidden"' in manifest,
        "MainActivity must own ordinary orientation/window configuration changes")
require("cleartextTrafficPermitted=\"false\"" in network, "network config must deny cleartext")
require("setAllowFileAccess(false)" in main, "WebView file access must stay disabled")
require("setAllowContentAccess(false)" in main, "WebView content access must stay disabled")
require("MIXED_CONTENT_NEVER_ALLOW" in main, "mixed content must stay blocked")
require("handler.cancel()" in main, "SSL errors must fail closed")
require("setAcceptThirdPartyCookies(target, false)" in main, "third-party cookies must stay disabled")
require("postUrl(authUrl" in main, "Android credential exchange must remain native HTTPS POST")
require("R.drawable.ic_mgw_launcher_art" in main, "native handoff must use approved raster")
require("ic_mgw_launcher_foreground" not in main, "deprecated simplified vector must not own native handoff")
require("/bot/android-auth.php" in shell, "Android auth URL must derive from trusted origin")
require("AndroidKeyStore" in credential, "device credential must remain in Android Keystore")
require("AES/GCM/NoPadding" in credential, "device credential must remain authenticated-encrypted")
require("SecureRandom" in credential and "new byte[32]" in credential, "device credential must remain 256-bit random")
require("@drawable/ic_mgw_launcher_art_inset" in launcher26, "API26 launcher must use accepted raster inset")
require("@drawable/ic_mgw_launcher_art_inset" in launcher33, "API33 launcher must use accepted raster inset")
require("@drawable/ic_mgw_launcher_art" in inset, "launcher inset must point at approved raster")
require("@drawable/ic_mgw_splash_transparent" in styles31, "Android 12+ platform splash center must remain transparent")
require("@color/mgw_splash_background" in styles and "@drawable/mgw_splash_background" not in styles,
        "legacy window background must be neutral")
require("#00000000" in transparent_splash, "platform splash center must be transparent")
require(not (APP / "src/main/res/drawable/ic_mgw_splash_mark.xml").exists(), "replacement splash mark must be removed")
require(not (APP / "src/main/res/drawable/mgw_splash_background.xml").exists(), "icon-bearing splash layer must be removed")

try:
    raster = base64.b64decode("".join(raster_b64.split()), validate=True)
    require(hashlib.sha256(raster).hexdigest() == "f096f1f4821e514abf880e37e3346e46a60e00f97713170591991a0cfea5dc5e",
            "approved Shield King raster checksum mismatch")
except Exception as exc:
    errors.append(f"invalid approved raster source: {exc}")

try:
    keystore = base64.b64decode("".join(keystore_b64.split()), validate=True)
    require(len(keystore) > 1000, "acceptance keystore source is unexpectedly small")
except Exception as exc:
    errors.append(f"invalid acceptance keystore source: {exc}")

for dangerous in ("setAllowUniversalAccessFromFileURLs(true)", "setAllowFileAccessFromFileURLs(true)", "addJavascriptInterface"):
    require(dangerous not in main, f"dangerous WebView capability found: {dangerous}")
for blocked in ("file", "content", "javascript", "data", "intent"):
    require(f'\"{blocked}\"' in policy, f"navigation policy must block {blocked}: URLs")

for xml_path in (
    manifest_path,
    APP / "src/main/res/values/strings.xml",
    APP / "src/main/res/values/styles.xml",
    APP / "src/main/res/values-v31/styles.xml",
    APP / "src/main/res/xml/network_security_config.xml",
    APP / "src/main/res/mipmap-anydpi-v26/ic_launcher.xml",
    APP / "src/main/res/mipmap-anydpi-v33/ic_launcher.xml",
):
    try:
        ET.parse(xml_path)
    except Exception as exc:
        errors.append(f"invalid XML {xml_path.relative_to(ROOT)}: {exc}")

java_names = {path.name for path in (APP / "src/main/java").rglob("*.java")}
require(java_names == {"MainActivity.java","NavigationPolicy.java","ShellConfig.java","DeviceCredentialStore.java"},
        "repair must keep the reviewed four-owner Android shell")

if errors:
    print("Android MVP-26.2 regression repair verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("Android MVP-26.2 regression repair verification PASS")
print("checks: accepted package/signature/raster/splash + Keystore auth + WebView hardening")
