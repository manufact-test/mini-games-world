#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import sys
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
APP = ROOT / "app"
MANIFEST = APP / "src/main/AndroidManifest.xml"
MAIN = APP / "src/main/java/com/minigamesworld/app/MainActivity.java"
BUILD = APP / "build.gradle"

errors: list[str] = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

manifest_text = MANIFEST.read_text(encoding="utf-8")
main = MAIN.read_text(encoding="utf-8")
build = BUILD.read_text(encoding="utf-8")

android_ns = "{http://schemas.android.com/apk/res/android}"
root = ET.parse(MANIFEST).getroot()
activity = None
for node in root.findall("./application/activity"):
    if node.attrib.get(android_ns + "name") == ".MainActivity":
        activity = node
        break

require(activity is not None, "MainActivity declaration missing")
if activity is not None:
    declared = set((activity.attrib.get(android_ns + "configChanges") or "").split("|"))
    required = {
        "orientation",
        "screenSize",
        "smallestScreenSize",
        "screenLayout",
        "keyboardHidden",
    }
    require(required.issubset(declared), f"MainActivity configChanges missing: {sorted(required - declared)}")

require("import android.content.res.Configuration;" in main, "Configuration import missing")
require("public void onConfigurationChanged(Configuration newConfig)" in main,
        "MainActivity must override onConfigurationChanged")

match = re.search(
    r"public void onConfigurationChanged\(Configuration newConfig\)\s*\{(?P<body>.*?)\n\s*\}",
    main,
    re.S,
)
require(match is not None, "Could not isolate onConfigurationChanged body")
if match is not None:
    body = match.group("body")
    require("super.onConfigurationChanged(newConfig);" in body, "configuration callback must call super")
    require("root.requestApplyInsets();" in body, "rotation must refresh system insets")
    require("root.requestLayout();" in body, "rotation must re-layout native root")
    forbidden = (
        "beginAndroidAuthentication",
        "attachFreshWebView",
        "loadInitialIntent",
        "showLoading(true)",
        "webView.loadUrl",
        "webView.postUrl",
        "recreate(",
        "destroyWebView",
    )
    for token in forbidden:
        require(token not in body, f"configuration callback must not cold-boot: {token}")

require("protected void onDestroy()" in main and "destroyWebView();" in main,
        "real Activity destruction must still dispose the WebView")
require("protected void onSaveInstanceState(Bundle outState)" in main and "webView.saveState" in main,
        "process/activity recovery must keep WebView saveState fallback")
require("webView.restoreState(webState)" in main,
        "process/activity recovery must keep WebView restoreState fallback")
require("versionCode 2605" in build, "orientation APK must use versionCode 2605")
require("applicationId 'com.minigamesworld.app.acceptance'" in build,
        "accepted Android package must remain unchanged")
require("mgw-acceptance-stable.keystore" in build,
        "accepted Android signing owner must remain unchanged")

if errors:
    print("MVP-26.3.4 orientation lifecycle verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("MVP-26.3.4 orientation lifecycle verification PASS")
print("checks: same Activity/WebView on rotation + real-destruction recovery preserved")
