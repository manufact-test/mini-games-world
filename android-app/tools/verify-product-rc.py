#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPO = ROOT.parent
APP = ROOT / "app"
JAVA = APP / "src/main/java"
errors: list[str] = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

def read(path: pathlib.Path) -> str:
    require(path.is_file(), f"missing file: {path.relative_to(REPO)}")
    return path.read_text(encoding="utf-8") if path.is_file() else ""

build = read(APP / "build.gradle")
main = read(JAVA / "com/minigamesworld/app/MainActivity.java")
platform = read(JAVA / "com/minigamesworld/app/platform/PlatformAdapters.java")
launch = read(REPO / "bot/helpers/WebAppLaunchUrl.php")
manifest = read(REPO / "app/runtime/client/version-manifest.php")
closure = read(REPO / "docs/MVP26_8_ANDROID_PRODUCT_RC.md")

version = re.search(r"\bversionCode\s+(\d+)\b", build)
require(version is not None and int(version.group(1)) >= 2615,
        "Android Product RC successor must not downgrade accepted v2615")
if version is not None and int(version.group(1)) == 2615:
    require("versionName '0.26.7.0-device-lifecycle-qa'" in build,
            "Historical accepted v2615 binary identity changed")
elif version is not None:
    require(re.search(r"\bversionName\s+'0\.26\.[0-9]+\.[^']+'", build) is not None,
            "Android successor versionName missing or malformed")
    require((APP / "src/main/res/values-en/strings.xml").is_file(),
            "Post-v2615 native successor must include English platform resources")
require("applicationId 'com.minigamesworld.app.acceptance'" in build,
        "accepted Android package identity changed")
require("mgw-acceptance-stable.keystore" in build,
        "accepted signing owner changed")

for verifier in [
    "verify-foundation.py",
    "verify-native-shell-settings.py",
    "verify-orientation-lifecycle.py",
    "verify-platform-adapters.py",
    "verify-product-parity.py",
    "verify-device-lifecycle-qa.py",
]:
    require((ROOT / "tools" / verifier).is_file(), f"required predecessor verifier missing: {verifier}")

require("PlatformAdapters.disabled()" in main,
        "Android RC must keep all external commercial/provider adapters disabled")
require("allDisabled()" in platform,
        "disabled provider registry contract missing")

provider_markers = [
    "com.android.billingclient",
    "com.google.firebase",
    "firebase-messaging",
    "com.google.android.gms.ads",
    "play-services-ads",
    "com.google.android.play:integrity",
    "play-integrity",
    "google-services",
]
combined_java = "\n".join(path.read_text(encoding="utf-8") for path in JAVA.rglob("*.java"))
for marker in provider_markers:
    require(marker.lower() not in build.lower(),
            f"provider SDK linked before MVP-29: {marker}")
    require(marker.lower() not in combined_java.lower(),
            f"provider SDK referenced before MVP-29: {marker}")

require("/app/v110.php" in launch,
        "Android RC must still launch the canonical shared v110 product runtime")
require("bundles=bundle-selector-click-hint-v11-performance" in launch,
        "accepted Store bundle performance corrective missing from launch identity")
require("bundle-selector-click-hint-v11-performance" in manifest,
        "accepted Store base owner cache identity missing")

for game_owner in [
    "games/tictactoe/renderer.js",
    "games/checkers/renderer.js",
    "games/chess/renderer.js",
    "games/reversi/renderer.js",
    "games/go/renderer.js",
    "games/four-in-a-row/renderer.js",
    "games/domino/renderer.js",
    "games/battleship/renderer.js",
]:
    require(game_owner in manifest, f"RC lost accepted game owner: {game_owner}")

require("Russian Android MGW is a complete app" in closure,
        "canonical MVP-26.8 closure statement missing")
require("Manual real-device acceptance: PASS" in closure,
        "manual Android acceptance record missing")
require("residual responsiveness difference" in closure,
        "accepted non-blocking performance residual must be documented")

if errors:
    print("MVP-26.8 Android Product RC verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("MVP-26.8 Android Product RC verification PASS")
print("closure: accepted v2615 baseline or monotonic localized Android successor + v110 + providers disabled")
