#!/usr/bin/env python3
from __future__ import annotations

import hashlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MANIFEST = ROOT / "bot/helpers/staging-e2e-runtime-files.txt"


def manifest_paths() -> list[str]:
    if not MANIFEST.is_file():
        raise RuntimeError("Staging E2E fingerprint manifest is unavailable.")

    paths: list[str] = []
    seen: set[str] = set()
    for raw_line in MANIFEST.read_text(encoding="utf-8").splitlines():
        path = raw_line.strip()
        if not path or path.startswith("#"):
            continue
        if path.startswith("/") or ".." in path or path in seen:
            raise RuntimeError(f"Unsafe or duplicate staging runtime path: {path}")
        seen.add(path)
        paths.append(path)

    if not paths:
        raise RuntimeError("Staging E2E fingerprint manifest is empty.")
    return paths


def calculate() -> str:
    parts: list[str] = []
    for relative_path in manifest_paths():
        source = ROOT / relative_path
        if not source.is_file():
            raise RuntimeError(f"Staging E2E runtime source is incomplete: {relative_path}")
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        parts.append(f"{relative_path}:{digest}")
    return hashlib.sha256("\n".join(parts).encode("utf-8")).hexdigest()


if __name__ == "__main__":
    print(calculate())
