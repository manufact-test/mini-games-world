#!/usr/bin/env bash
set -euo pipefail

cat scripts/ci/r8-payload-*.txt | base64 -d | tar -xJf - -C .
rm -f scripts/ci/r8-payload-*.txt
rm -f bot/tests/TemporaryR8SourceExportTest.php

python3 - <<'PY'
from pathlib import Path

path = Path('.github/workflows/ci.yml')
text = path.read_text()
text = text.replace('permissions:\n  contents: write\n', 'permissions:\n  contents: read\n')
text = text.replace('          ref: ${{ github.event.pull_request.head.ref }}\n', '')
start = text.find('      - name: Apply canonical R8 source\n')
end = text.find('      - name: Run repository checks\n')
if start != -1 and end != -1 and end > start:
    text = text[:start] + text[end:]
path.write_text(text)
PY

rm -f scripts/ci/r8-apply.sh
