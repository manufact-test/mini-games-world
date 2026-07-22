#!/usr/bin/env bash
set -euo pipefail
exec bash "$(cd -- "$(dirname -- "$0")" && pwd -P)/ops/runtime/run-staging-read-only-checkpoint.sh"
