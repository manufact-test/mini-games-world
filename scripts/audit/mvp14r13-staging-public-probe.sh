#!/usr/bin/env bash
set -Eeuo pipefail

STAGING_BASE='https://seashell-okapi-889488.hostingersite.com'
PRODUCTION_BASE='https://lemonchiffon-gerbil-545102.hostingersite.com'
TMP_DIR="$(mktemp -d)"
NETWORK_FAILURES=0

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

print_selected_json() {
  local body_file="$1"
  python3 - "$body_file" <<'PY'
import json
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
try:
    data = json.loads(path.read_text(encoding='utf-8'))
except Exception:
    print('json: invalid-or-not-json')
    raise SystemExit(0)

selected = {
    'ok': data.get('ok'),
    'service': data.get('service'),
    'status': data.get('status'),
    'build': data.get('build'),
    'environment': data.get('environment'),
    'storage_driver': data.get('storage_driver'),
    'server': data.get('server'),
    'storage': data.get('storage'),
}
print('json: ' + json.dumps(selected, ensure_ascii=False, sort_keys=True))
PY
}

print_html_markers() {
  local body_file="$1"
  local markers
  markers="$(grep -aEo 'data-hotfix-build="[^"]+"|(?:\./)?assets/js/[A-Za-z0-9._/-]+\.js\?v=[A-Za-z0-9._-]+' "$body_file" | LC_ALL=C sort -u | head -40 || true)"
  if [[ -n "$markers" ]]; then
    while IFS= read -r marker; do
      printf 'marker: %s\n' "$marker"
    done <<< "$markers"
  else
    echo 'marker: none'
  fi
}

probe() {
  local label="$1"
  local url="$2"
  local kind="$3"
  local headers="$TMP_DIR/${label}.headers"
  local body="$TMP_DIR/${label}.body"
  local meta

  echo
  echo "--- ${label} ---"
  echo "url: ${url}"

  if ! meta="$(curl \
      --silent \
      --show-error \
      --location \
      --connect-timeout 10 \
      --max-time 25 \
      --dump-header "$headers" \
      --output "$body" \
      --write-out '%{http_code}|%{url_effective}|%{content_type}|%{size_download}|%{time_total}' \
      "$url")"; then
    echo 'network: failed'
    NETWORK_FAILURES=$((NETWORK_FAILURES + 1))
    return
  fi

  IFS='|' read -r status effective content_type size_download time_total <<< "$meta"
  echo "http_status: ${status}"
  echo "effective_url: ${effective}"
  echo "content_type: ${content_type:-unknown}"
  echo "size_download: ${size_download}"
  echo "time_total_sec: ${time_total}"
  echo "body_sha256: $(sha256sum "$body" | awk '{print $1}')"

  awk 'BEGIN{IGNORECASE=1}
       /^cache-control:/ || /^location:/ || /^x-content-type-options:/ || /^referrer-policy:/ || /^set-cookie:/ {
         gsub(/\r$/, ""); print "header: " $0
       }' "$headers" | tail -20

  if [[ "$kind" == 'json' ]]; then
    print_selected_json "$body"
  else
    print_html_markers "$body"
  fi
}

echo 'MVP-14R13.1 public read-only staging audit'
echo "audit_utc: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "repository_commit: $(git rev-parse HEAD)"
echo 'request_policy: unauthenticated GET only; no cookies, tokens, request bodies or mutations'

probe 'staging_health' "${STAGING_BASE}/bot/health.php" json
probe 'staging_app_root' "${STAGING_BASE}/app/" html
probe 'staging_v110_entry' "${STAGING_BASE}/app/v110.php?v=1123" html
probe 'staging_clean_health' "${STAGING_BASE}/app/runtime/api.php?action=health" json
probe 'staging_clean_entry' "${STAGING_BASE}/app/runtime/index.php" html

probe 'production_health' "${PRODUCTION_BASE}/bot/health.php" json
probe 'production_v110_entry' "${PRODUCTION_BASE}/app/v110.php?v=1123" html
probe 'production_clean_health' "${PRODUCTION_BASE}/app/runtime/api.php?action=health" json
probe 'production_clean_entry' "${PRODUCTION_BASE}/app/runtime/index.php" html

if (( NETWORK_FAILURES > 0 )); then
  echo
  echo "audit_result: failed (${NETWORK_FAILURES} network failures)"
  exit 1
fi

echo
echo 'audit_result: completed'
