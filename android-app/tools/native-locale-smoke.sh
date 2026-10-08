#!/usr/bin/env bash
# Deterministic Android resource-runtime proof: diagnostic APK has no HTTPS base URL,
# so the native configuration error is visible without network, auth or web content.
set -Eeuo pipefail
APK="${1:?usage: native-locale-smoke.sh /path/to/diagnostic-app.apk}"
PKG='com.minigamesworld.app.acceptance'
ACTIVITY="$PKG/com.minigamesworld.app.MainActivity"
OUT="${MGW_LOCALE_EVIDENCE_DIR:-/tmp/mgw-native-locale}"
mkdir -p "$OUT"
adb wait-for-device
adb shell input keyevent KEYCODE_WAKEUP >/dev/null 2>&1 || true
adb shell wm dismiss-keyguard >/dev/null 2>&1 || true
adb install -r "$APK"

verify_locale() {
  local locale="$1"
  local expected="$2"
  local label="$3"
  echo "== Verify native Android locale: $label / $locale =="
  adb shell cmd locale set-app-locales "$PKG" --locales "$locale"
  adb shell cmd locale get-app-locales "$PKG" | tee "$OUT/$label-config.txt"
  adb shell am force-stop "$PKG"
  adb shell am start -W -n "$ACTIVITY" > "$OUT/$label-launch.txt"
  sleep 2
  local success=0
  for attempt in 1 2 3; do
    adb shell uiautomator dump /sdcard/mgw-native-locale.xml >/dev/null 2>&1 || true
    adb pull /sdcard/mgw-native-locale.xml "$OUT/$label-window.xml" >/dev/null 2>&1 || true
    if [[ -s "$OUT/$label-window.xml" ]] && grep -Fq "$expected" "$OUT/$label-window.xml"; then
      success=1
      break
    fi
    sleep 3
  done
  adb exec-out screencap -p > "$OUT/$label.png"
  if [[ "$success" != 1 ]]; then
    echo "Native locale mismatch ($label): expected visible text: $expected" >&2
    cat "$OUT/$label-window.xml" >&2 || true
    return 1
  fi
  if adb logcat -d AndroidRuntime:E '*:S' | grep -F "$PKG"; then
    echo 'MGW Android crash during locale switch' >&2
    return 1
  fi
}
verify_locale 'en-US' 'App setup is incomplete' 'en'
verify_locale 'ru-RU' 'Сборка не настроена' 'ru'
# An unsupported native locale must resolve to complete RU fallback strings.
verify_locale 'fr-FR' 'Сборка не настроена' 'fallback-fr'
adb shell cmd locale set-app-locales "$PKG" --locales ''
echo 'MVP-27.4: EN + RU + unsupported-locale fallback visible Android UI PASS'
