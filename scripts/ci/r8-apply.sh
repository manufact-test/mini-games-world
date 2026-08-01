#!/usr/bin/env bash
set -euo pipefail

cat scripts/ci/r8-payload-*.txt | base64 -d | tar -xJf - -C .
