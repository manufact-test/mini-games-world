#!/usr/bin/env python3
from pathlib import Path
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "app"
MAIN = APP / "src/main/java/com/minigamesworld/app/MainActivity.java"
MANIFEST = APP / "src/main/AndroidManifest.xml"
BUILD = APP / "build.gradle"
SMOKE = ROOT / "tools/device-lifecycle-smoke.sh"

def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")

def require(ok: bool, message: str) -> None:
    if not ok:
        raise SystemExit(message)

main = read(MAIN)
manifest_text = read(MANIFEST)
build = read(BUILD)
smoke = read(SMOKE)

version = re.search(r"\bversionCode\s+(\d+)\b", build)
require(version is not None and int(version.group(1)) == 2615,
        "MVP-26.7 candidate must use real versionCode 2615")
require("versionName '0.26.7.0-device-lifecycle-qa'" in build,
        "MVP-26.7 versionName missing")
require(re.search(r"\bminSdk\s+26\b", build) is not None, "minSdk 26 must remain supported")
require(re.search(r"\btargetSdk\s+36\b", build) is not None, "targetSdk 36 must remain current")
require(re.search(r"\bcompileSdk\s+36\b", build) is not None, "compileSdk 36 must remain current")

root = ET.fromstring(manifest_text)
ns = "{http://schemas.android.com/apk/res/android}"
app = root.find("application")
require(app is not None, "application node missing")
require(app.attrib.get(ns + "allowBackup") == "false", "Android backup must stay disabled")
require(app.attrib.get(ns + "usesCleartextTraffic") == "false", "cleartext traffic must stay disabled")

activity = None
for node in app.findall("activity"):
    if node.attrib.get(ns + "name") == ".MainActivity":
        activity = node
        break
require(activity is not None, "MainActivity declaration missing")
require(activity.attrib.get(ns + "windowSoftInputMode") == "adjustResize",
        "IME must remain adjustResize")
require(activity.attrib.get(ns + "launchMode") == "singleTask",
        "MainActivity must keep singleTask ownership")
declared = set((activity.attrib.get(ns + "configChanges") or "").split("|"))
required_configs = {"orientation","screenSize","smallestScreenSize","screenLayout","keyboardHidden"}
require(required_configs.issubset(declared),
        f"MainActivity configChanges missing: {sorted(required_configs - declared)}")

for token in [
    "protected void onPause()",
    "webView.onPause()",
    "protected void onResume()",
    "webView.onResume()",
    "public void onConfigurationChanged(Configuration newConfig)",
    "protected void onSaveInstanceState(Bundle outState)",
    "webView.saveState(webState)",
    "webView.restoreState(webState)",
    "public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail)",
    "destroyWebView();",
    "retryCurrentPage()",
    "moveTaskToBack(true)",
    "root.requestApplyInsets()",
    "WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout()",
    "insets.getSystemWindowInsetLeft()",
]:
    require(token in main, f"lifecycle invariant missing: {token}")

require("addJavascriptInterface" not in main,
        "MVP-26.7 must not introduce a privileged JavaScript bridge")
require("setAllowFileAccess(false)" in main and "setAllowContentAccess(false)" in main,
        "WebView local file/content access must stay disabled")

for token in [
    "am send-trim-memory",
    "am kill",
    "am force-stop",
    "KEYCODE_HOME",
    "user_rotation",
    "wm size",
    "wm density",
    "cmd connectivity airplane-mode",
    "assert_package_alive",
    "assert_activity_visible",
]:
    require(token in smoke, f"device smoke coverage missing: {token}")

print("MVP-26.7 Android device/lifecycle QA contract PASS")
