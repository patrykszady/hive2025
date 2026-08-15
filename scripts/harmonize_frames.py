#!/usr/bin/env python3
"""Grade one timelapse frame toward the anchor frame's colors — globally.

    harmonize_frames.py <reference> <target> <output>

Two global stages, no masks, nothing regional (an earlier region-based
version washed over tree canopies and was rejected — a uniform mapping can
never invent an edge):

  1. Monge-Kantorovich linear color transfer (the film-grading technique,
     via the color-matcher package) — matches the full color covariance,
     so saturation and tonal character follow the anchor, not just channel
     means. SELF-LIMITING: its strength scales down so the mean shift it
     applies can never exceed the same clamps stage 2 obeys — frames whose
     content differs wildly from the anchor (a lush spring lawn vs a dirt
     construction yard) would otherwise be contaminated by the anchor's
     palette. Skipped cleanly when color-matcher isn't installed.
  2. Clamped per-channel LAB statistics matching — the safe exposure /
     contrast / cast alignment that always runs.

Exit 0 on success (JSON diagnostics on stdout), 1 on failure.
"""
import json
import os
import sys

import cv2
import numpy as np

# L does the heavy lifting; a/b stay gentle — matched materials, honest hues.
CLAMPS = [(0.80, 1.25, 26.0), (0.94, 1.06, 8.0), (0.94, 1.06, 8.0)]

# MKL is the strongest stage and the riskiest: it ROTATES color space, so a
# frame whose content differs wildly from the reference (an empty room vs one
# full of cabinets) can come back blotchy. HARMONIZE_MKL=0 skips it and
# leaves the safe stages (tone curve + clamped LAB stats) to do the work.
MKL_ENABLED = os.environ.get("HARMONIZE_MKL", "1") != "0"
# How far to carry the grade — SPLIT by what a viewer forgives. Luminance
# genuinely changes through a day, so L keeps 45% of each frame's own light
# (a full-strength match reads flat and over-processed). But a MATERIAL —
# pink floor paper, blue tape, a cabinet face — is the same object in every
# frame, and a white-balance drift that tints it salmon in one frame and
# rose in the next reads as flicker, so chroma converges much harder.
STRENGTH = float(os.environ.get("HARMONIZE_STRENGTH", "0.55"))
STRENGTH_CHROMA = float(os.environ.get("HARMONIZE_STRENGTH_CHROMA", "0.90"))

try:
    from color_matcher import ColorMatcher

    MATCHER = ColorMatcher() if MKL_ENABLED else None
except Exception:  # noqa: BLE001 — any import/init failure = stage skipped
    MATCHER = None


def mkl_stage(target, ref):
    """Covariance transfer with channel-level discipline.

    L is taken from the transfer wholesale — tonal character (shadows,
    highlights, contrast) cannot shift a hue. The a/b chroma channels are
    the contamination vector (MKL ROTATES color space, and a frame whose
    content differs from the anchor — spring lawn vs red sheathing — comes
    out tinted), so their per-pixel deltas are strength-limited: the 95th
    percentile of |delta| may not exceed the honest-cast cap stage 2 uses.
    """
    matched = MATCHER.transfer(src=target.astype(np.float64), ref=ref.astype(np.float64), method='mkl')
    matched = np.clip(np.real(matched), 0, 255).astype(np.uint8)

    orig_lab = cv2.cvtColor(target, cv2.COLOR_BGR2LAB).astype(np.float64)
    matched_lab = cv2.cvtColor(matched, cv2.COLOR_BGR2LAB).astype(np.float64)

    out_lab = orig_lab.copy()
    out_lab[:, :, 0] = matched_lab[:, :, 0]

    strengths = []
    for c in (1, 2):
        delta = matched_lab[:, :, c] - orig_lab[:, :, c]
        p95 = float(np.percentile(np.abs(delta), 95))
        cap = CLAMPS[c][2]
        s = min(1.0, cap / p95) if p95 > 1e-6 else 1.0
        out_lab[:, :, c] = orig_lab[:, :, c] + s * delta
        strengths.append(round(s, 3))

    out = cv2.cvtColor(np.clip(out_lab, 0, 255).astype(np.uint8), cv2.COLOR_LAB2BGR)

    return out, strengths


def tone_curve_stage(target, ref, strength=0.55):
    """Monotone luminance curve matching the reference's tone DISTRIBUTION.

    A linear gain cannot brighten a dark house and calm a bright sky at
    once; a tone curve — a pure function of pixel value, no masks, exactly
    Lightroom's shadows/highlights move — can. The curve is CDF matching on
    L, smoothed, slope-clamped to [0.4, 2.2] so it can't posterize, and
    applied at partial strength.
    """
    t_lab = cv2.cvtColor(target, cv2.COLOR_BGR2LAB)
    r_lab = cv2.cvtColor(ref, cv2.COLOR_BGR2LAB)

    t_hist = np.bincount(t_lab[:, :, 0].ravel(), minlength=256).astype(np.float64)
    r_hist = np.bincount(r_lab[:, :, 0].ravel(), minlength=256).astype(np.float64)
    t_cdf = np.cumsum(t_hist) / max(t_hist.sum(), 1)
    r_cdf = np.cumsum(r_hist) / max(r_hist.sum(), 1)

    lut = np.interp(t_cdf, r_cdf, np.arange(256, dtype=np.float64))
    lut = cv2.GaussianBlur(lut.reshape(-1, 1).astype(np.float32), (0, 0), 6.0).ravel().astype(np.float64)
    for i in range(1, 256):
        lut[i] = min(max(lut[i], lut[i - 1] + 0.4), lut[i - 1] + 2.2)
    lut = np.clip(lut, 0, 255)

    base = np.arange(256, dtype=np.float64)
    lut = base + strength * (lut - base)

    out_lab = t_lab.astype(np.float64)
    out_lab[:, :, 0] = np.take(lut, t_lab[:, :, 0])

    return cv2.cvtColor(np.clip(out_lab, 0, 255).astype(np.uint8), cv2.COLOR_LAB2BGR)


def stat_stage(target, ref):
    """Clamped per-channel LAB mean/std easing toward the reference."""
    ref_lab = cv2.cvtColor(ref, cv2.COLOR_BGR2LAB).astype(np.float64)
    lab = cv2.cvtColor(target, cv2.COLOR_BGR2LAB).astype(np.float64)

    gains, offsets = [], []
    for c, (lo, hi, cap) in enumerate(CLAMPS):
        mean, std = lab[:, :, c].mean(), lab[:, :, c].std()
        t_mean, t_std = ref_lab[:, :, c].mean(), ref_lab[:, :, c].std()
        gain = float(np.clip(t_std / max(std, 1e-3), lo, hi))
        offset = float(np.clip(t_mean - mean * gain, -cap, cap))
        lab[:, :, c] = lab[:, :, c] * gain + offset
        gains.append(round(gain, 3))
        offsets.append(round(offset, 1))

    return cv2.cvtColor(np.clip(lab, 0, 255).astype(np.uint8), cv2.COLOR_LAB2BGR), gains, offsets


def main():
    if len(sys.argv) != 4:
        print(json.dumps({"ok": False, "reason": "usage"}))
        return 1

    ref = cv2.imread(sys.argv[1])
    target = cv2.imread(sys.argv[2])

    if ref is None or target is None:
        print(json.dumps({"ok": False, "reason": "unreadable"}))
        return 1

    # The honest-black warp border is NOT photo — it must neither influence
    # the grade nor be lifted by it. (A graded border reads as a gray halo.)
    black = cv2.cvtColor(target, cv2.COLOR_BGR2LAB)[:, :, 0] < 8

    chroma_strengths = None
    out = target

    if MATCHER is not None:
        try:
            out, chroma_strengths = mkl_stage(out, ref)
        except Exception:  # noqa: BLE001 — transfer failure = stage skipped
            out = target

    out = tone_curve_stage(out, ref)
    out, gains, offsets = stat_stage(out, ref)

    # Ease the grade back toward the frame as shot — per LAB channel, so
    # light keeps its character while material colors converge (see the
    # STRENGTH note above).
    if STRENGTH < 1.0 or STRENGTH_CHROMA < 1.0:
        t_lab = cv2.cvtColor(target, cv2.COLOR_BGR2LAB).astype(np.float64)
        o_lab = cv2.cvtColor(out, cv2.COLOR_BGR2LAB).astype(np.float64)
        for c, s in ((0, STRENGTH), (1, STRENGTH_CHROMA), (2, STRENGTH_CHROMA)):
            o_lab[:, :, c] = t_lab[:, :, c] + s * (o_lab[:, :, c] - t_lab[:, :, c])
        out = cv2.cvtColor(np.clip(o_lab, 0, 255).astype(np.uint8), cv2.COLOR_LAB2BGR)

    out[black] = target[black]

    if not cv2.imwrite(sys.argv[3], out, [int(cv2.IMWRITE_JPEG_QUALITY), 90]):
        print(json.dumps({"ok": False, "reason": "write failed"}))
        return 1

    print(json.dumps({"ok": True, "strength": STRENGTH, "mkl_chroma_strengths": chroma_strengths,
                      "gains": gains, "offsets": offsets}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
