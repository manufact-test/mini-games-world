#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import sys
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
APP = ROOT / "app"
REPO = ROOT.parent
errors: list[str] = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

def read(path: pathlib.Path) -> str:
    require(path.is_file(), f"missing file: {path.relative_to(REPO)}")
    return path.read_text(encoding="utf-8") if path.is_file() else ""

manifest_path = APP / "src/main/AndroidManifest.xml"
manifest = read(manifest_path)
main = read(APP / "src/main/java/com/minigamesworld/app/MainActivity.java")
build = read(APP / "build.gradle")
shell = read(REPO / "app/assets/js/main-v110-handoff-shell.js")
version_manifest = read(REPO / "app/runtime/client/version-manifest.php")
main_css = read(REPO / "app/assets/css/main.css")
sheet_component = read(REPO / "app/assets/js/components/sheet.js")

# Back ownership: fixed native -> web event, no privileged JS bridge, safe fallback.
require("mgw:android-back-request" in main, "native Back must dispatch the fixed Android-back request event")
require("evaluateJavascript(script" in main, "native Back must ask the internal web shell before fallback")
require("moveTaskToBack(true)" in main, "root Back must background the task instead of destroying accepted session state")
require("addJavascriptInterface" not in main, "native shell must not introduce a privileged JavaScript bridge")
require("mgw:android-back-request" in shell, "web shell must own the fixed Android-back request")
require("closeSheet();" in shell, "Android Back must close the active sheet first")
require("['profile','store','tournaments'].includes(screen)" in shell,
        "Android Back must return persistent shell screens to Home")
require("showScreen('home')" in shell, "persistent shell Back must return to Home")
require("['game','search']" not in shell, "closure must not add generic game/search abandonment routing")

# Keyboard + safe area.
root = ET.parse(manifest_path).getroot()
android_ns = "{http://schemas.android.com/apk/res/android}"
activity = None
for node in root.findall("./application/activity"):
    if node.attrib.get(android_ns + "name") == ".MainActivity":
        activity = node
        break
require(activity is not None, "MainActivity declaration missing")
if activity is not None:
    require(activity.attrib.get(android_ns + "windowSoftInputMode") == "adjustResize",
            "MainActivity must explicitly resize for the software keyboard")

require("Api30Insets.apply(view, insets)" in main, "Android 11+ safe-area owner missing")
require("WindowInsets.Type.systemBars()" in main, "modern safe-area must include system bars")
require("WindowInsets.Type.displayCutout()" in main, "modern safe-area must include display cutout")
require("getSystemWindowInsetBottom()" in main, "API26-29 inset fallback must remain available")
require("mgw-short-visual-viewport" in main_css and "--mgw-sheet-viewport-height" in main_css,
        "short landscape/IME sheet must bind to the measured visual viewport")
require("visualViewport" in sheet_component and "scrollIntoView" in sheet_component,
        "sheet runtime must follow keyboard visual-viewport changes and reveal focused inputs")
require("overflow-y:auto!important" in main_css,
        "short landscape/IME sheet must remain whole-dialog scrollable")

# Native accessibility basics.
require("brandMark.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_NO)" in main,
        "decorative brand mark must stay out of accessibility focus")
require("loading.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_NO)" in main,
        "duplicate loading spinner must stay out of accessibility focus")
require("loadingText.setAccessibilityLiveRegion(View.ACCESSIBILITY_LIVE_REGION_POLITE)" in main,
        "loading text must expose a polite live region")
require("errorTitle.setAccessibilityLiveRegion(View.ACCESSIBILITY_LIVE_REGION_ASSERTIVE)" in main,
        "blocking native errors must expose an assertive live region")
require("announceCurrentError()" in main and "announceForAccessibility" in main,
        "blocking native errors must be announced")
require("replacement.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_YES)" in main,
        "WebView product surface must remain accessibility-visible")

# Cache/version/signing/package boundaries.
version_match = re.search(r"\bversionCode\s+(\d+)\b", build)
require(version_match is not None and int(version_match.group(1)) >= 2610,
        "future Android candidates must not regress below accepted native-shell versionCode 2610")
require("applicationId 'com.minigamesworld.app.acceptance'" in build,
        "accepted package identity must remain unchanged")
require("mgw-acceptance-stable.keystore" in build,
        "accepted signing owner must remain unchanged")
require(re.search(r"main-v110-handoff-shell\.js\?v=1170[^'\n]*mvp26_3=native-shell-settings-v1", version_manifest) is not None,
        "active localized shell cache owner must preserve the Android Back corrective")
require("mvp26_3=landscape-ime-sheet-v2" in version_manifest,
        "active CSS cache owner must publish the visual-viewport landscape IME corrective")
require("sheet.js?v=1111&mvp26_3=visual-viewport-ime-v2" in version_manifest,
        "active sheet runtime cache owner must publish the visual-viewport corrective")

if errors:
    print("MVP-26.3 native shell/settings closure verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("MVP-26.3 native shell/settings closure verification PASS")
print("checks: Back ownership + keyboard resize + landscape IME reachability + safe-area/cutout + accessibility basics + frozen package/signing")
