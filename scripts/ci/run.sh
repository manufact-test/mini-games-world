#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

echo "== PHP syntax =="
php_count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  php_count=$((php_count + 1))
done < <(git ls-files -z '*.php')
echo "PHP syntax passed: ${php_count} files"

echo "== PHP smoke tests =="
test_count=0
test_failures=0
while IFS= read -r test_file; do
  [[ -z "$test_file" ]] && continue
  test_count=$((test_count + 1))
  if ! php -d auto_prepend_file="$ROOT/scripts/ci/php-strict.php" "$test_file"; then
    echo "FAILED: ${test_file}" >&2
    test_failures=$((test_failures + 1))
  fi
done < <(git ls-files 'bot/tests/*Test.php' | LC_ALL=C sort)

if (( test_failures > 0 )); then
  echo "PHP smoke tests failed: ${test_failures} of ${test_count} files" >&2
  exit 1
fi

echo "PHP smoke tests passed: ${test_count} files"

echo "== Shell syntax =="
shell_count=0
while IFS= read -r -d '' file; do
  bash -n "$file"
  shell_count=$((shell_count + 1))
done < <(git ls-files -z '*.sh')
echo "Shell syntax passed: ${shell_count} files"

echo "== JSON validation =="
node scripts/ci/check-json.mjs

echo "== JavaScript syntax, imports and query versions =="
node scripts/ci/check-js.mjs

echo "== Secret and private-file scan =="
node scripts/ci/check-secrets.mjs

echo "All Mini Games World CI checks passed."
