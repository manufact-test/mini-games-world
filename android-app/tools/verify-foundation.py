#!/usr/bin/env python3
from __future__ import annotations

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
network = text(APP / "src/main/res/xml/network_security_config.xml")

require("compileSdk 36" in build, "compileSdk must remain 36 for this foundation")
require("targetSdk 36" in build, "targetSdk must remain 36 for this foundation")
require("minSdk 26" in build, "minSdk must remain 26 unless separately reviewed")
require("MGW_BASE_URL" in build, "MGW URL must be injected through build config")
require("usesCleartextTraffic=\"false\"" in manifest, "cleartext traffic must be disabled")
require("cleartextTrafficPermitted=\"false\"" in network, "network security config must deny cleartext")
require("setAllowFileAccess(false)" in main, "WebView file access must be disabled")
require("setAllowContentAccess(false)" in main, "WebView content access must be disabled")
require("MIXED_CONTENT_NEVER_ALLOW" in main, "mixed content must be blocked")
require("handler.cancel()" in main, "SSL errors must fail closed")
require("setWebContentsDebuggingEnabled(BuildConfig.DEBUG)" in main, "WebView debugging must be build-type gated")
require("addJavascriptInterface" not in main, "privileged JavaScript bridge is forbidden in the foundation")
for dangerous in ("setAllowUniversalAccessFromFileURLs(true)", "setAllowFileAccessFromFileURLs(true)"):
    require(dangerous not in main, f"dangerous WebView setting found: {dangerous}")
for blocked in ("file", "content", "javascript", "data", "intent"):
    require(f'\"{blocked}\"' in policy, f"navigation policy must explicitly block {blocked}: URLs")

for xml_path in (
    manifest_path,
    APP / "src/main/res/values/strings.xml",
    APP / "src/main/res/values/styles.xml",
    APP / "src/main/res/xml/network_security_config.xml",
):
    try:
        ET.parse(xml_path)
    except Exception as exc:
        errors.append(f"invalid XML {xml_path.relative_to(ROOT)}: {exc}")

java_sources = list((APP / "src/main/java").rglob("*.java"))
require(len(java_sources) == 3, "foundation must keep exactly three production Java owners")

if errors:
    print("Android foundation verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("Android foundation verification PASS")
print("checks: build contract, XML, WebView hardening, navigation policy, owner count")
