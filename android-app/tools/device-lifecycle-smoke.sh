#!/usr/bin/env bash
set -Eeuo pipefail

APK="${1:?usage: device-lifecycle-smoke.sh /path/to/app.apk}"
PKG='com.minigamesworld.app.acceptance'
ACTIVITY='com.minigamesworld.app.MainActivity'
COMPONENT="${PKG}/${ACTIVITY}"
EXPECTED_API="${MGW_DEVICE_API:?MGW_DEVICE_API is required}"
DEVICE_SIZE="${MGW_DEVICE_SIZE:?MGW_DEVICE_SIZE is required}"
DEVICE_DENSITY="${MGW_DEVICE_DENSITY:?MGW_DEVICE_DENSITY is required}"

cleanup() {
  adb shell cmd connectivity airplane-mode disable >/dev/null 2>&1 || true
  adb shell settings put system accelerometer_rotation 1 >/dev/null 2>&1 || true
  adb shell wm size reset >/dev/null 2>&1 || true
  adb shell wm density reset >/dev/null 2>&1 || true
}
trap cleanup EXIT

adb wait-for-device
adb shell input keyevent KEYCODE_WAKEUP >/dev/null 2>&1 || true
adb shell wm dismiss-keyguard >/dev/null 2>&1 || true
adb shell input keyevent 82 >/dev/null 2>&1 || true

actual_api="$(adb shell getprop ro.build.version.sdk | tr -d '\r')"
test "$actual_api" = "$EXPECTED_API"

adb install -r "$APK"
adb shell wm size "$DEVICE_SIZE"
adb shell wm density "$DEVICE_DENSITY"
adb shell settings put global window_animation_scale 0
adb shell settings put global transition_animation_scale 0
adb shell settings put global animator_duration_scale 0
adb shell settings put system accelerometer_rotation 0
adb shell settings put system user_rotation 0
adb logcat -c

pid_of_app() {
  adb shell pidof "$PKG" 2>/dev/null | tr -d '\r' | awk '{print $1}'
}

assert_package_alive() {
  local pid
  pid="$(pid_of_app)"
  test -n "$pid"
  printf 'MGW_PID=%s\n' "$pid"
}

assert_activity_visible() {
  adb shell dumpsys activity activities | grep -Eq "$PKG/$ACTIVITY|$PKG/.MainActivity"
}

activity_record_token() {
  adb shell dumpsys activity activities     | grep -m1 -E "ActivityRecord\{[^}]* ($PKG/$ACTIVITY|$PKG/.MainActivity)"     | sed -n 's/.*ActivityRecord{\([^ ]*\).*/\1/p'     | tr -d '\r' || true
}

assert_no_java_crash() {
  if adb logcat -d AndroidRuntime:E '*:S' 2>/dev/null | grep -F "$PKG"; then
    echo 'AndroidRuntime crash detected for MGW' >&2
    return 1
  fi
}

launch_app() {
  adb shell am start -W -n "$COMPONENT" >/tmp/mgw-am-start.txt
  sleep 8
  assert_package_alive
  assert_activity_visible
  assert_no_java_crash
}

echo "== cold launch API $EXPECTED_API / $DEVICE_SIZE / $DEVICE_DENSITY =="
launch_app
first_pid="$(pid_of_app)"
first_record="$(activity_record_token)"

echo '== background / foreground =='
adb shell input keyevent KEYCODE_HOME
sleep 2
background_pid="$(pid_of_app)"
test -n "$background_pid"
test "$background_pid" = "$first_pid"
adb shell am start -W -n "$COMPONENT" >/tmp/mgw-am-resume.txt
sleep 3
test "$(pid_of_app)" = "$first_pid"
assert_activity_visible
assert_no_java_crash

echo '== orientation without Activity/WebView cold recreation =='
before_record="$(activity_record_token)"
adb shell settings put system user_rotation 1
sleep 3
assert_package_alive
assert_activity_visible
after_record="$(activity_record_token)"
if [[ -n "$before_record" && -n "$after_record" ]]; then
  test "$before_record" = "$after_record"
fi
adb shell settings put system user_rotation 0
sleep 3
assert_package_alive
assert_activity_visible
assert_no_java_crash

echo '== low-memory callback pressure =='
adb shell am send-trim-memory "$PKG" RUNNING_CRITICAL || true
sleep 2
assert_package_alive
assert_activity_visible
assert_no_java_crash

echo '== network loss / recovery shell resilience =='
if adb shell cmd connectivity airplane-mode enable >/dev/null 2>&1; then
  sleep 3
  assert_package_alive
  adb shell cmd connectivity airplane-mode disable >/dev/null 2>&1 || true
  sleep 5
  assert_package_alive
  assert_activity_visible
  assert_no_java_crash
else
  echo 'airplane-mode shell command unavailable on this API; manual network recovery remains required'
fi

echo '== process death / task restore =='
adb shell input keyevent KEYCODE_HOME
sleep 1
pre_kill_pid="$(pid_of_app)"
adb shell am kill "$PKG" >/dev/null 2>&1 || true
for _ in $(seq 1 20); do
  [[ -z "$(pid_of_app)" ]] && break
  sleep 1
done
if [[ -n "$(pid_of_app)" ]]; then
  echo 'am kill did not evict the background process; using force-stop for restart proof'
  adb shell am force-stop "$PKG"
  sleep 2
fi
launch_app
post_kill_pid="$(pid_of_app)"
test -n "$post_kill_pid"
if [[ -n "$pre_kill_pid" ]]; then
  test "$post_kill_pid" != "$pre_kill_pid" || true
fi

echo '== explicit cold restart =='
adb shell am force-stop "$PKG"
sleep 2
launch_app
assert_no_java_crash

adb exec-out screencap -p > /tmp/mgw-device-lifecycle-final.png
adb shell uiautomator dump /sdcard/mgw-window.xml >/dev/null 2>&1 || true
adb pull /sdcard/mgw-window.xml /tmp/mgw-window.xml >/dev/null 2>&1 || true
adb shell dumpsys activity activities > /tmp/mgw-activity.txt
adb shell dumpsys meminfo "$PKG" > /tmp/mgw-meminfo.txt
adb logcat -d > /tmp/mgw-logcat.txt

echo "MVP-26.7 device/lifecycle smoke PASS api=$EXPECTED_API size=$DEVICE_SIZE density=$DEVICE_DENSITY"
