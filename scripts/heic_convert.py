#!/usr/bin/env python3
"""HEIC/HEIF → full-quality JPEG with its EXIF carried over.

    heic_convert.py <source.heic> <dest.jpg>

Why not ImageMagick: the IM6 builds on dev and prod decode HEIC pixels but
drop the container's metadata, and the shot date / GPS are exactly what the
importer reads off the file (captions show the date — a "before" photo
stamped with its upload day reads as wrong data).

Orientation is baked INTO the pixels here and the EXIF tag reset to 1:
pillow-heif applies the container's rotation at decode and exif_transpose
applies any EXIF rotation on top, so leaving a stale Orientation tag behind
would make the importer's own orientate() pass rotate the image twice.

Exit 0 with "ok" on stdout, non-zero otherwise.
"""
import sys

import piexif
import pillow_heif
from PIL import Image, ImageOps

pillow_heif.register_heif_opener()


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: heic_convert.py <src> <dst>", file=sys.stderr)
        return 1

    src, dst = sys.argv[1], sys.argv[2]

    try:
        img = Image.open(src)
        raw_exif = img.info.get("exif")
        img = ImageOps.exif_transpose(img)
        if img.mode != "RGB":
            img = img.convert("RGB")
    except Exception as e:  # noqa: BLE001 — any decode failure is the same outcome
        print(f"decode failed: {e}", file=sys.stderr)
        return 1

    kwargs = {"format": "JPEG", "quality": 92}
    if raw_exif:
        try:
            parsed = piexif.load(raw_exif)
            parsed["0th"][piexif.ImageIFD.Orientation] = 1
            kwargs["exif"] = piexif.dump(parsed)
        except Exception:  # noqa: BLE001 — keep the original block over losing it
            kwargs["exif"] = raw_exif

    try:
        img.save(dst, **kwargs)
    except Exception as e:  # noqa: BLE001
        print(f"encode failed: {e}", file=sys.stderr)
        return 1

    print("ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
