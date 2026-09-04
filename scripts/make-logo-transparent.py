#!/usr/bin/env python3
"""
Drop a school seal into public/images with its background knocked out.

SchoolLetterhead looks up two files by name — public/images/deped-logo.png and
public/images/school-logo.png — so the seals on every printed form and the
consent letter come from the filesystem rather than from code. This script is
how a seal gets there: it takes whatever the school has (a JPEG off a memo, a
PNG with a white card behind it) and writes the PNG those lookups expect.

The background is removed by flooding inward from the four corners, never by
deleting every white pixel in the image. Both seals are rings with white
*inside* them — the torch panel on the DepEd seal, the shield field on the Sta.
Ana one — and a rule that deletes white wherever it finds it punches those out,
leaving a seal you can see the paper through. Flooding from the edges can only
ever reach the background, because the ring itself stops it.

Usage:
    python scripts/make-logo-transparent.py <source> --as deped
    python scripts/make-logo-transparent.py <source> --as school
    python scripts/make-logo-transparent.py <source> --out path/to/file.png

Options:
    --threshold N   how close to white counts as background (0-255, default 236)
    --keep-margin   leave the transparent border in place instead of trimming
"""

from __future__ import annotations

import argparse
import sys
from collections import deque
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    sys.exit("Pillow is required: python -m pip install --user Pillow")

ROOT = Path(__file__).resolve().parent.parent

# The two names SchoolLetterhead::DEPED_LOGO and ::SCHOOL_LOGO resolve.
TARGETS = {
    "deped": ROOT / "public" / "images" / "deped-logo.png",
    "school": ROOT / "public" / "images" / "school-logo.png",
}


def knockout(image: Image.Image, threshold: int) -> Image.Image:
    """Clear the background, working inward from the edges."""
    image = image.convert("RGBA")
    width, height = image.size
    pixels = image.load()

    def is_background(x: int, y: int) -> bool:
        r, g, b, a = pixels[x, y]
        # Already transparent counts: a source PNG may be part-way there.
        return a == 0 or (r >= threshold and g >= threshold and b >= threshold)

    seen = bytearray(width * height)
    queue: deque[tuple[int, int]] = deque()

    # Every edge pixel is a starting point, not only the four corners: a seal
    # photographed off-centre can touch one side of the frame, and starting at
    # the corners alone would leave the background on that side behind.
    for x in range(width):
        for y in (0, height - 1):
            queue.append((x, y))
    for y in range(height):
        for x in (0, width - 1):
            queue.append((x, y))

    while queue:
        x, y = queue.popleft()
        index = y * width + x
        if seen[index]:
            continue
        seen[index] = 1
        if not is_background(x, y):
            continue

        r, g, b, _ = pixels[x, y]
        pixels[x, y] = (r, g, b, 0)

        if x > 0:
            queue.append((x - 1, y))
        if x + 1 < width:
            queue.append((x + 1, y))
        if y > 0:
            queue.append((x, y - 1))
        if y + 1 < height:
            queue.append((x, y + 1))

    return image


def soften_edges(image: Image.Image, threshold: int) -> Image.Image:
    """
    Fade the halo a hard cut leaves behind.

    A seal scanned or saved as JPEG has a gradient a pixel or two wide where the
    ink meets the paper. Those pixels are lighter than the threshold, so the
    flood stops just short of them and they survive as a pale fringe. Any pixel
    still opaque but touching transparency gets an alpha scaled by how far from
    white it actually is, which lets the fringe fall away without eating the
    edge of the artwork.
    """
    width, height = image.size
    pixels = image.load()
    faded: list[tuple[int, int, int]] = []

    for y in range(height):
        for x in range(width):
            r, g, b, a = pixels[x, y]
            if a == 0:
                continue

            touches_background = (
                (x > 0 and pixels[x - 1, y][3] == 0)
                or (x + 1 < width and pixels[x + 1, y][3] == 0)
                or (y > 0 and pixels[x, y - 1][3] == 0)
                or (y + 1 < height and pixels[x, y + 1][3] == 0)
            )
            if not touches_background:
                continue

            lightest = max(r, g, b)
            if lightest < threshold - 40:
                continue

            # 0 at pure white, full alpha by the time the pixel is clearly ink.
            distance = (255 - lightest) / max(1, 255 - (threshold - 40))
            faded.append((x, y, int(round(min(1.0, distance) * 255))))

    for x, y, alpha in faded:
        r, g, b, _ = pixels[x, y]
        pixels[x, y] = (r, g, b, alpha)

    return image


def main() -> int:
    parser = argparse.ArgumentParser(description="Make a seal's background transparent.")
    parser.add_argument("source", type=Path, help="the image to convert")
    parser.add_argument("--as", dest="target", choices=sorted(TARGETS), help="write to the seal path this app reads")
    parser.add_argument("--out", type=Path, help="write somewhere else instead")
    parser.add_argument("--threshold", type=int, default=236, help="how close to white counts as background (0-255)")
    parser.add_argument("--keep-margin", action="store_true", help="do not trim the transparent border")
    args = parser.parse_args()

    if not args.source.is_file():
        return fail(f"No such file: {args.source}")
    if not args.target and not args.out:
        return fail("Say where it goes: --as deped, --as school, or --out <path>.")
    if not 0 <= args.threshold <= 255:
        return fail("--threshold must be between 0 and 255.")

    destination = args.out or TARGETS[args.target]

    with Image.open(args.source) as source:
        image = knockout(source, args.threshold)

    image = soften_edges(image, args.threshold)

    if not args.keep_margin:
        # Trim the cleared border so the seal fills its box on the letterhead
        # rather than sitting inside invisible padding of its own.
        box = image.getbbox()
        if box:
            image = image.crop(box)

    total = image.width * image.height
    opaque = total - image.getchannel("A").histogram()[0]
    if opaque == total:
        return fail(
            f"Nothing was cleared — the background is darker than --threshold {args.threshold}. "
            "Raise it, or check the image actually has a plain border."
        )
    if opaque == 0:
        return fail("Everything was cleared — lower --threshold.")

    destination.parent.mkdir(parents=True, exist_ok=True)
    image.save(destination, "PNG", optimize=True)

    cleared = 100 * (total - opaque) / total
    print(f"Wrote {destination} — {image.width}x{image.height}, {cleared:.0f}% transparent.")
    return 0


def fail(message: str) -> int:
    print(f"error: {message}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
