#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail() {
  printf 'Android foundation build preflight FAILED: %s\n' "$1" >&2
  exit 1
}

command -v java >/dev/null 2>&1 || fail "JDK 17+ is required"
command -v gradle >/dev/null 2>&1 || fail "Gradle 9.5+ is required on this isolated build host"

JAVA_VERSION="$(java -version 2>&1 | head -n1)"
GRADLE_VERSION="$(gradle --version 2>/dev/null | awk '/^Gradle / {print $2; exit}')"

[[ -n "${MGW_BASE_URL:-}" ]] || fail "MGW_BASE_URL must be supplied explicitly"
[[ "$MGW_BASE_URL" == https://* ]] || fail "MGW_BASE_URL must use HTTPS"

python3 tools/verify-foundation.py

printf 'Java: %s\n' "$JAVA_VERSION"
printf 'Gradle: %s\n' "$GRADLE_VERSION"
printf 'MGW origin: configured (value intentionally not echoed)\n'

gradle --no-daemon clean test lint assembleDebug

APK="app/build/outputs/apk/debug/app-foundation-debug.apk"
[[ -f "$APK" ]] || APK="app/build/outputs/apk/debug/app-debug.apk"
[[ -f "$APK" ]] || fail "assembleDebug completed but APK was not found at the expected output path"

printf 'Android foundation build PASS\n'
printf 'APK: %s\n' "$APK"
if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$APK"
fi
