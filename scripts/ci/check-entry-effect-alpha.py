#!/usr/bin/env python3
from __future__ import annotations

import math
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
ASSETS = [
    ROOT / 'app/assets/media/cosmetics/entry-effects/entry-effect-01-celestial-gate.webp',
    ROOT / 'app/assets/media/cosmetics/entry-effects/entry-effect-02-portal-knight.webp',
    ROOT / 'app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp',
]

MIN_ALPHA_FRACTION = 0.05
MIN_OPAQUE_FRACTION = 0.01
MIN_RGB_STDDEV = 4.0
EXPECTED_SIZE = (640, 480)


def inspect(path: Path) -> dict[str, float | int | str | tuple[int, int]]:
    if not path.is_file():
        raise RuntimeError(f'missing Entry Effect asset: {path.relative_to(ROOT)}')

    with Image.open(path) as source:
        if source.format != 'WEBP':
            raise RuntimeError(f'{path.name}: expected WEBP, got {source.format!r}')
        image = source.convert('RGBA')

    if image.size != EXPECTED_SIZE:
        raise RuntimeError(f'{path.name}: expected {EXPECTED_SIZE[0]}x{EXPECTED_SIZE[1]}, got {image.width}x{image.height}')

    pixels = list(image.getdata())
    total = len(pixels)
    visible = [px for px in pixels if px[3] > 8]
    opaque = sum(1 for px in pixels if px[3] > 245)
    alpha_fraction = len(visible) / total if total else 0.0
    opaque_fraction = opaque / total if total else 0.0

    if visible:
        luminance = [(px[0] + px[1] + px[2]) / 3.0 for px in visible]
        mean = sum(luminance) / len(luminance)
        variance = sum((value - mean) ** 2 for value in luminance) / len(luminance)
        rgb_stddev = math.sqrt(variance)
    else:
        rgb_stddev = 0.0

    return {
        'name': path.name,
        'size': image.size,
        'bytes': path.stat().st_size,
        'alpha_fraction': alpha_fraction,
        'opaque_fraction': opaque_fraction,
        'rgb_stddev': rgb_stddev,
    }


def main() -> None:
    failures: list[str] = []
    for path in ASSETS:
        result = inspect(path)
        print(
            f"{result['name']}: {result['size'][0]}x{result['size'][1]} "
            f"bytes={result['bytes']} alpha={result['alpha_fraction']:.4f} "
            f"opaque={result['opaque_fraction']:.4f} rgb_stddev={result['rgb_stddev']:.2f}"
        )
        if float(result['alpha_fraction']) <= MIN_ALPHA_FRACTION:
            failures.append(f"{result['name']}: visible alpha fraction must be > {MIN_ALPHA_FRACTION}")
        if float(result['opaque_fraction']) <= MIN_OPAQUE_FRACTION:
            failures.append(f"{result['name']}: opaque pixel fraction must be > {MIN_OPAQUE_FRACTION}")
        if float(result['rgb_stddev']) <= MIN_RGB_STDDEV:
            failures.append(f"{result['name']}: visual RGB variance must be > {MIN_RGB_STDDEV}")

    if failures:
        raise SystemExit('Entry Effect asset integrity failed:\n- ' + '\n- '.join(failures))

    print('Entry Effect asset integrity: PASS')


if __name__ == '__main__':
    main()
