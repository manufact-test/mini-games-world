#!/usr/bin/env python3
"""MVP-27.4: native Android resource ownership, locale parity and safe fallback."""
from __future__ import annotations

import pathlib
import re
import sys
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
RES = ROOT / "app/src/main/res"
MAIN = ROOT / "app/src/main/java/com/minigamesworld/app/MainActivity.java"
MANIFEST = ROOT / "app/src/main/AndroidManifest.xml"
BUILD = ROOT / "app/build.gradle"
errors: list[str] = []


def require(ok: bool, problem: str) -> None:
    if not ok:
        errors.append(problem)


def resource_catalog(path: pathlib.Path) -> dict[str, str]:
    require(path.is_file(), f"Missing native locale resource: {path}")
    if not path.is_file():
        return {}
    root = ET.parse(path).getroot()
    require(root.tag == "resources", f"Bad resources root: {path}")
    catalog: dict[str, str] = {}
    for entry in root:
        if entry.tag != "string":
            continue
        key = entry.get("name")
        require(bool(key) and key not in catalog, f"Duplicate/unnamed string in {path}: {key}")
        if not key:
            continue
        value = "".join(entry.itertext()).strip()
        require(bool(value), f"Blank native translation: {path} / {key}")
        catalog[key] = value
    return catalog


def placeholders(value: str) -> list[str]:
    # Compare positional format signatures; literal %% is not a parameter.
    return sorted(re.findall(r"%(?:\d+\$)?[-+# 0,(]*\d*(?:\.\d+)?[a-zA-Z]", value))


ru = resource_catalog(RES / "values/strings.xml")
en = resource_catalog(RES / "values-en/strings.xml")
require(bool(ru) and set(ru) == set(en),
        f"EN/RU resource key mismatch: RU only={sorted(set(ru) - set(en))}, EN only={sorted(set(en) - set(ru))}")
for key in set(ru) & set(en):
    require(placeholders(ru[key]) == placeholders(en[key]),
            f"Format-argument mismatch for {key}")
    require(not re.search(r"[\u0400-\u052f]", en[key]),
            f"Untranslated Cyrillic copy in English resource: {key}")
require(ru.get("app_name") == en.get("app_name") == "Mini Games World",
        "Branded app identity must be stable across locales")

source = MAIN.read_text(encoding="utf-8")
manifest = MANIFEST.read_text(encoding="utf-8")
build = BUILD.read_text(encoding="utf-8")

native_refs = set(re.findall(r"R\.string\.([a-zA-Z_][a-zA-Z0-9_]*)", source))
for key in sorted(native_refs - set(ru)):
    require(False, f"Native Java string reference has no default/English resource: {key}")
require('@string/app_name' in manifest, "Manifest must use locale-resolved app name")

# All user-visible native Java UI/copy goes through the Android resource resolver.
require(not re.search(r"[\u0400-\u052f]", source),
        "Hardcoded Cyrillic text in native Activity")
for marker in [
    "loadingText.setText(R.string.loading)",
    "retry.setText(R.string.retry)",
    "errorTitle.setText(R.string.network_error_title)",
    "errorText.setText(textResource)",
    "getString(R.string.reauth_prompt_title)",
    "getString(R.string.reauth_prompt_text)",
    "request.setTitle(getString(R.string.download_title))",
    "request.setDescription(getString(R.string.download_description))",
    "request.setDescription(getString(R.string.file_download_description))",
    "R.string.download_started", "R.string.download_failed",
    "R.string.file_download_started", "R.string.file_download_failed",
    "R.string.download_permission_required", "R.string.file_download_permission_required",
    "R.string.external_link_error", "R.string.security_error_text",
]:
    require(marker in source, f"Unowned/absent native locale surface: {marker}")

web_download = source.split("private void beginWebDownload(", 1)
require(len(web_download) == 2, "Generic file download path missing")
if len(web_download) == 2:
    section = web_download[1].split("private void clearPendingWebDownload()", 1)[0]
    for marker in (
        "R.string.file_download_failed",
        "R.string.file_download_started",
        "R.string.file_download_description",
    ):
        require(marker in section, f"Generic attachment path is mislabeled as account ZIP: {marker}")
    require("R.string.download_started" not in section
            and "R.string.download_failed" not in section
            and "R.string.download_description" not in section,
            "Generic attachment download must never show ZIP account-data copy")

# A platform locale change must recreate the Activity and re-resolve resources.
# Do not intercept locale in configChanges or set a global/process custom locale.
activity = ET.fromstring(manifest).find("./application/activity")
android_ns = "{http://schemas.android.com/apk/res/android}"
require(activity is not None, "Android shell activity missing")
if activity is not None:
    config_changes = set((activity.get(android_ns + "configChanges") or "").split("|"))
    require("locale" not in config_changes and "layoutDirection" not in config_changes,
            "Android native locale changes must not be swallowed without resource refresh")
for forbidden in ("Locale.setDefault(", ".setLocale(", "addJavascriptInterface(", "X-MGW-Locale"):
    require(forbidden not in source,
            f"Native locale must be resolved by Android, not a second account/web owner: {forbidden}")

version = re.search(r"\bversionCode\s+(\d+)\b", build)
require(version is not None and int(version.group(1)) >= 2616,
        "Localization APK must upgrade monotonically over accepted v2615")
require("applicationId 'com.minigamesworld.app.acceptance'" in build,
        "Accepted app package changed")
require("mgw-acceptance-stable.keystore" in build,
        "Accepted APK signing identity changed")
require("PlatformAdapters.disabled()" in source,
        "Disabled external provider integrations changed")

if errors:
    print("MVP-27.4 Android native locale contract FAILED", file=sys.stderr)
    for error in errors:
        print(f"- {error}", file=sys.stderr)
    raise SystemExit(1)

print("MVP-27.4 Android native locale contract PASS")
print(f"native keys={len(ru)}; RU default + EN complete; native errors/reauth/ZIP/files localized")
print("Android OS locale owns native resources; Web/account language remains MVP-27.5")
