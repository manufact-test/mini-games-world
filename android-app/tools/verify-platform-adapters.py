#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPO = ROOT.parent
APP = ROOT / "app"
JAVA = APP / "src/main/java"
PLATFORM = JAVA / "com/minigamesworld/app/platform"
errors: list[str] = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

def read(path: pathlib.Path) -> str:
    require(path.is_file(), f"missing file: {path.relative_to(REPO)}")
    return path.read_text(encoding="utf-8") if path.is_file() else ""

required_interfaces = [
    "BillingAdapter",
    "AdsAdapter",
    "PushAdapter",
    "IntegrityAdapter",
    "AnalyticsAdapter",
    "DeepLinkAdapter",
]

base = read(PLATFORM / "PlatformAdapter.java")
registry = read(PLATFORM / "PlatformAdapters.java")
main = read(JAVA / "com/minigamesworld/app/MainActivity.java")
build = read(APP / "build.gradle")
tests = read(APP / "src/test/java/com/minigamesworld/app/platform/PlatformAdaptersTest.java")

require("enum Mode" in base and "DISABLED" in base and "MOCK" in base and "PROVIDER" in base,
        "platform adapter mode contract must expose disabled/mock/provider states")

for name in required_interfaces:
    text = read(PLATFORM / f"{name}.java")
    require(f"interface {name} extends PlatformAdapter" in text,
            f"{name} must remain provider-neutral through PlatformAdapter")

require(re.search(
            r"^\s*private\s+final\s+PlatformAdapters\s+platformAdapters\s*=\s*PlatformAdapters\.disabled\(\);\s*$",
            main,
            re.MULTILINE
        ) is not None,
        "Android shell must actively own a disabled platform-adapter registry")
require("\\n" not in main,
        "MainActivity must not contain literal escaped newline tokens that can hide runtime ownership inside comments")
require("new DisabledBillingAdapter()" in registry, "disabled billing owner missing")
require("new DisabledAdsAdapter()" in registry, "disabled ads owner missing")
require("new DisabledPushAdapter()" in registry, "disabled push owner missing")
require("new DisabledIntegrityAdapter()" in registry, "disabled integrity owner missing")
require("new DisabledAnalyticsAdapter()" in registry, "disabled analytics owner missing")
require("new DisabledDeepLinkAdapter()" in registry, "disabled deep-link owner missing")
require("LaunchResult.UNAVAILABLE" in registry, "disabled billing must not start purchase flow")
require("ShowResult.UNAVAILABLE" in registry, "disabled ads must not show rewarded flow")
require("RegistrationResult.UNAVAILABLE" in registry, "disabled push must not register")
require("Optional.empty()" in registry, "disabled token/link adapters must return no provider result")
require("allDisabled()" in registry, "registry must expose a bounded disabled-state assertion")

for phrase in [
    "defaultRegistryKeepsEveryProviderDisabled",
    "disabledBillingAndAdsCannotStartProviderFlows",
    "disabledPushAndIntegrityStayUnavailable",
    "disabledAnalyticsAndProviderDeepLinksAreSafeNoOps",
]:
    require(phrase in tests, f"missing unit contract: {phrase}")

version = re.search(r"\bversionCode\s+(\d+)\b", build)
require(version is not None and int(version.group(1)) == 2611,
        "MVP-26.5 candidate must use versionCode 2611")
require("versionName '0.26.5.1-disabled-platform-adapters'" in build,
        "MVP-26.5 candidate versionName missing")
require("applicationId 'com.minigamesworld.app.acceptance'" in build,
        "accepted Android package identity changed")
require("mgw-acceptance-stable.keystore" in build,
        "accepted signing owner changed")

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
combined_java = "\n".join(
    path.read_text(encoding="utf-8")
    for path in JAVA.rglob("*.java")
)
for marker in provider_markers:
    require(marker.lower() not in build.lower(),
            f"provider SDK must stay absent from Gradle before MVP-29: {marker}")
    require(marker.lower() not in combined_java.lower(),
            f"provider SDK must stay absent from Android Java before MVP-29: {marker}")

billing_api = read(PLATFORM / "BillingAdapter.java")
ads_api = read(PLATFORM / "AdsAdapter.java")
require("launchPurchase(String productId)" in billing_api,
        "BillingAdapter must stay a transport-only purchase launch seam")
require("showRewarded(String placementId)" in ads_api,
        "AdsAdapter must stay a transport-only presentation seam")
for forbidden_method in ["creditBalance(", "grantInventory(", "applyReward(", "settlePurchase("]:
    require(forbidden_method not in billing_api and forbidden_method not in ads_api,
            f"provider adapter must not expose canonical economy mutation: {forbidden_method}")

if errors:
    print("MVP-26.5 disabled platform adapters verification FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("MVP-26.5 disabled platform adapters verification PASS")
print("checks: six interfaces + disabled defaults + no provider SDK coupling + frozen package/signing")
