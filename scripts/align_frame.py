#!/usr/bin/env python3
"""Align one timelapse frame onto a reference frame.

Pipeline (each stage measurable, each stage safe to skip):

  1. SIFT keypoints + Lowe ratio matches (ORB fallback), RANSAC SIMILARITY —
     shift + rotation + uniform zoom, 4 DOF. Deliberately NOT a homography:
     8-DOF perspective fits whichever plane dominates and keystones the rest,
     which reads as distortion between frames.
  2. ECC refinement at half scale — squeezes out the sub-pixel residual the
     keypoint stage leaves behind.
  3. Conservative optical-flow mesh — the parallax cleaner. Dense flow,
     downsampled to a coarse grid, heavily blurred and magnitude-capped, so
     real geometry eases into place while scene CHANGES (new cabinets, moved
     tools) are too sharp/local to survive the smoothing and stay untouched.
  4. Photometric match — the L channel's mean/std eased toward the anchor
     (clamped gain/offset), so different-day lighting doesn't flicker.

    align_frame.py <reference> <target> <output> [min_inliers]

Exit codes: 0 aligned · 2 not confident (caller keeps original) · 1 error.
Prints one JSON diagnostics line either way.
"""

import json
import os
import sys

import cv2
import numpy as np

FLOW_ENABLED = os.environ.get("ALIGN_FLOW", "1") != "0"
PHOTO_ENABLED = os.environ.get("ALIGN_PHOTO", "1") != "0"
GEOMETRY_ENABLED = os.environ.get("ALIGN_GEOMETRY", "1") != "0"
# Six numbers "Lm,Ls,am,as,bm,bs": the whole sequence's median LAB profile.
# When set, every frame is eased toward IT instead of toward the reference —
# so the anchor's own quirks (a light flare, say) don't become the standard.
TARGET_STATS = os.environ.get("ALIGN_TARGET", "")
FLOW_CAP_PX = 30.0
FLOW_GRID = 24  # flow is averaged into cells this size (full-res px) then blurred


def lab_stats(image):
    lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB).astype(np.float32)
    return [float(v) for c in range(3) for v in (lab[:, :, c].mean(), lab[:, :, c].std())]


def stats_mode(paths):
    """--stats f1 f2 ... -> median LAB profile across the given frames."""
    rows = []
    for path in paths:
        image = cv2.imread(path)
        if image is not None:
            rows.append(lab_stats(image))
    if not rows:
        print(json.dumps({"ok": False}))
        sys.exit(1)
    med = np.median(np.array(rows), axis=0)
    print(json.dumps({"ok": True, "target": ",".join(f"{v:.2f}" for v in med)}))
    sys.exit(0)


def fail(reason, **extra):
    print(json.dumps({"aligned": False, "reason": reason, **extra}))
    sys.exit(2)


def detect_and_match(ref_gray, target_gray):
    """SIFT + ratio test when available (more accurate), else ORB + cross-check."""
    try:
        sift = cv2.SIFT_create(nfeatures=6000)
        ref_kp, ref_desc = sift.detectAndCompute(ref_gray, None)
        target_kp, target_desc = sift.detectAndCompute(target_gray, None)

        if ref_desc is None or target_desc is None:
            raise RuntimeError

        matcher = cv2.BFMatcher(cv2.NORM_L2)
        pairs = matcher.knnMatch(target_desc, ref_desc, k=2)
        matches = [m for m, n in pairs if m.distance < 0.75 * n.distance]

        return ref_kp, target_kp, matches, "sift"
    except Exception:
        orb = cv2.ORB_create(nfeatures=4000)
        ref_kp, ref_desc = orb.detectAndCompute(ref_gray, None)
        target_kp, target_desc = orb.detectAndCompute(target_gray, None)

        if ref_desc is None or target_desc is None:
            fail("too few keypoints")

        matcher = cv2.BFMatcher(cv2.NORM_HAMMING, crossCheck=True)
        matches = sorted(matcher.match(target_desc, ref_desc), key=lambda m: m.distance)

        return ref_kp, target_kp, matches, "orb"


def ecc_refine(ref_gray, warped_gray):
    """Sub-pixel similarity correction between the warped frame and the anchor.

    Runs at half scale for speed; returns a full-scale 2x3 matrix (or None).
    """
    scale = 0.5
    small_ref = cv2.resize(ref_gray, None, fx=scale, fy=scale)
    small_warped = cv2.resize(warped_gray, None, fx=scale, fy=scale)

    warp = np.eye(2, 3, dtype=np.float32)
    criteria = (cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, 60, 1e-5)

    try:
        correlation, warp = cv2.findTransformECC(
            small_ref, small_warped, warp, cv2.MOTION_EUCLIDEAN, criteria, None, 5
        )
    except cv2.error:
        return None, 0.0

    # ECC maps ref -> warped; we need the inverse, scaled back to full size.
    warp_full = warp.copy()
    warp_full[:, 2] /= scale
    inverse = cv2.invertAffineTransform(warp_full)

    return inverse, float(correlation)


def flow_refine(ref_gray, warped, warped_gray, strong=False):
    """Ease residual parallax with a heavily-smoothed, capped flow field.

    The smoothing radius (~3 grid cells ≈ 70px) is what keeps this safe on a
    construction site: genuine parallax is smooth and low-frequency across the
    image, while scene CHANGES are sharp and local — they average away instead
    of melting the new state into the old one.
    """
    height, width = ref_gray.shape
    scale = 0.25
    small_ref = cv2.resize(ref_gray, None, fx=scale, fy=scale)
    small_warped = cv2.resize(warped_gray, None, fx=scale, fy=scale)

    dis = cv2.DISOpticalFlow_create(cv2.DISOPTICAL_FLOW_PRESET_MEDIUM)
    # calc(I0=ref, I1=warped) gives ref(x) ≈ warped(x + flow(x)) — exactly the
    # backward map remap() wants for resampling WARPED onto the anchor's grid.
    flow_small = dis.calc(small_ref, small_warped, None)

    flow = cv2.resize(flow_small, (width, height), interpolation=cv2.INTER_LINEAR) / scale

    # Coarse grid + wide blur: keep only the low-frequency component. Strong
    # mode (heavy-parallax frames) allows finer cells and a longer reach —
    # still far too smooth to bend individual objects.
    grid = FLOW_GRID // 2 if strong else FLOW_GRID
    cap = FLOW_CAP_PX * 2 if strong else FLOW_CAP_PX
    grid_w, grid_h = max(2, width // grid), max(2, height // grid)
    coarse = cv2.resize(flow, (grid_w, grid_h), interpolation=cv2.INTER_AREA)
    coarse = cv2.GaussianBlur(coarse, (0, 0), 3.0)
    flow = cv2.resize(coarse, (width, height), interpolation=cv2.INTER_LINEAR)

    magnitude = np.linalg.norm(flow, axis=2, keepdims=True)
    over = magnitude > cap
    np.divide(flow * cap, magnitude, out=flow, where=over)

    grid_x, grid_y = np.meshgrid(np.arange(width, dtype=np.float32), np.arange(height, dtype=np.float32))
    map_x = grid_x + flow[:, :, 0]
    map_y = grid_y + flow[:, :, 1]

    eased = cv2.remap(warped, map_x, map_y, cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)

    return eased, float(np.median(magnitude))


def photometric_match(ref, image):
    """Ease the frame's full LAB stats toward the target profile (clamped).

    Target = the sequence median when ALIGN_TARGET is set (color-matching the
    whole sequence to its collective look), else the reference frame. All
    three channels: L evens out exposure, a/b evens out color cast — the
    difference between frames "navigating nicely" and pulsing warm/cool.
    """
    if TARGET_STATS:
        target = [float(v) for v in TARGET_STATS.split(",")]
    else:
        target = lab_stats(ref)

    img_lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB).astype(np.float32)

    # L does the heavy lifting (exposure/flare). a/b stay GENTLE: measured on
    # real sequences, color casts barely drift — aggressive matching there
    # created differences instead of removing them.
    clamps = [(0.85, 1.18, 20.0), (0.96, 1.04, 5.0), (0.96, 1.04, 5.0)]
    gains = []

    for channel, (lo, hi, max_off) in enumerate(clamps):
        t_mean, t_std = target[channel * 2], target[channel * 2 + 1]
        mean = float(img_lab[:, :, channel].mean())
        std = float(img_lab[:, :, channel].std())

        gain = float(np.clip(t_std / max(std, 1e-3), lo, hi))
        offset = float(np.clip(t_mean - mean * gain, -max_off, max_off))
        img_lab[:, :, channel] = np.clip(img_lab[:, :, channel] * gain + offset, 0, 255)
        gains.append(round(gain, 3))

    return cv2.cvtColor(img_lab.astype(np.uint8), cv2.COLOR_LAB2BGR), gains[0], 0.0


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == "--stats":
        stats_mode(sys.argv[2:])

    if len(sys.argv) < 4:
        print(json.dumps({"aligned": False, "reason": "usage"}))
        sys.exit(1)

    ref_path, target_path, out_path = sys.argv[1:4]
    min_inliers = int(sys.argv[4]) if len(sys.argv) > 4 else 25

    ref = cv2.imread(ref_path)
    target = cv2.imread(target_path)

    if ref is None or target is None:
        print(json.dumps({"aligned": False, "reason": "unreadable input"}))
        sys.exit(1)

    if not GEOMETRY_ENABLED:
        # Photo-only (the sequence anchor): identity geometry, colors eased to
        # the shared target like every other frame.
        out = target
        if PHOTO_ENABLED:
            out, luma_gain, _ = photometric_match(ref, target)
        if not cv2.imwrite(out_path, out, [int(cv2.IMWRITE_JPEG_QUALITY), 90]):
            print(json.dumps({"aligned": False, "reason": "write failed"}))
            sys.exit(1)
        print(json.dumps({"aligned": True, "photo_only": True}))
        sys.exit(0)

    ref_gray = cv2.cvtColor(ref, cv2.COLOR_BGR2GRAY)
    target_gray = cv2.cvtColor(target, cv2.COLOR_BGR2GRAY)

    ref_kp, target_kp, matches, engine = detect_and_match(ref_gray, target_gray)

    if len(matches) < min_inliers:
        fail("too few matches", matches=len(matches), engine=engine)

    src = np.float32([target_kp[m.queryIdx].pt for m in matches]).reshape(-1, 1, 2)
    dst = np.float32([ref_kp[m.trainIdx].pt for m in matches]).reshape(-1, 1, 2)

    height, width = ref.shape[:2]

    # ── candidate transforms, judged by MEASURED overlap error ────────────
    # Similarity can't absorb the perspective of standing a step aside; a free
    # homography absorbs it but can keystone visibly. So: fit all three, cap
    # how much extra corner-shift the homography may add over its affine part
    # (imperceptible ≤ 12px at full res), and let the pixels pick the winner.
    def overlap_error(warped_gray_small, ref_gray_small):
        return float(np.mean(cv2.absdiff(warped_gray_small, ref_gray_small)))

    small = 0.5
    ref_small = cv2.resize(ref_gray, None, fx=small, fy=small)

    candidates = []

    sim, sim_mask = cv2.estimateAffinePartial2D(
        src, dst, method=cv2.RANSAC, ransacReprojThreshold=5.0, maxIters=5000
    )

    if sim is None:
        fail("no transform", matches=len(matches), engine=engine)

    inliers = int(sim_mask.sum())

    if inliers < min_inliers:
        fail("too few inliers", matches=len(matches), inliers=inliers, engine=engine)

    scale = float(np.hypot(sim[0, 0], sim[0, 1]))
    if scale < 0.7 or scale > 1.4:
        fail("implausible transform", inliers=inliers, scale=round(scale, 3))

    candidates.append(("similarity", sim, False))

    affine, _ = cv2.estimateAffine2D(src, dst, method=cv2.RANSAC, ransacReprojThreshold=5.0, maxIters=5000)
    if affine is not None:
        sx = float(np.hypot(affine[0, 0], affine[1, 0]))
        sy = float(np.hypot(affine[0, 1], affine[1, 1]))
        if 0.7 < sx < 1.4 and 0.7 < sy < 1.4:
            candidates.append(("affine", affine, False))

    homography, h_mask = cv2.findHomography(src, dst, cv2.RANSAC, 5.0)
    if homography is not None and abs(homography[2, 2]) > 1e-6:
        h = homography / homography[2, 2]
        corners = np.array([[0, 0, 1], [width, 0, 1], [0, height, 1], [width, height, 1]], dtype=np.float64).T
        projected = h @ corners
        projected = projected[:2] / projected[2]
        affine_part = h.copy()
        affine_part[2] = [0, 0, 1]
        approx = (affine_part @ corners)[:2]
        keystone = float(np.max(np.linalg.norm(projected - approx, axis=0)))
        det = float(np.linalg.det(h[:2, :2]))
        if keystone <= 12.0 and 0.5 < det < 2.0:
            candidates.append(("homography", h, True))

    scored = []
    for name, matrix, perspective in candidates:
        if perspective:
            small_matrix = np.diag([small, small, 1.0]) @ matrix @ np.diag([1 / small, 1 / small, 1.0])
            trial = cv2.warpPerspective(cv2.resize(target_gray, None, fx=small, fy=small), small_matrix,
                                        (ref_small.shape[1], ref_small.shape[0]),
                                        flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
        else:
            small_matrix = matrix.copy()
            small_matrix[:, 2] *= small
            trial = cv2.warpAffine(cv2.resize(target_gray, None, fx=small, fy=small), small_matrix,
                                   (ref_small.shape[1], ref_small.shape[0]),
                                   flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
        scored.append((overlap_error(trial, ref_small), name, matrix, perspective))

    scored.sort(key=lambda row: row[0])
    _, chosen, matrix, perspective = scored[0]

    if perspective:
        warped = cv2.warpPerspective(target, matrix, (width, height),
                                     flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
    else:
        warped = cv2.warpAffine(target, matrix, (width, height),
                                flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)

    transform = matrix if not perspective else matrix[:2]

    # ── stage 2: sub-pixel ECC ────────────────────────────────────────────
    ecc_cc = 0.0
    refinement, ecc_cc = ecc_refine(ref_gray, cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY))
    if refinement is not None:
        shift_px = float(np.hypot(refinement[0, 2], refinement[1, 2]))
        if shift_px < 40:  # a huge ECC "correction" means it diverged — skip
            warped = cv2.warpAffine(warped, refinement, (width, height),
                                    flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)

    # ── stage 3: parallax easing ──────────────────────────────────────────
    # Heavy-parallax frames (poor global fit) get a second, stronger pass —
    # each pass judged by measurement: kept only if it tightens the frame.
    flow_median = 0.0
    if FLOW_ENABLED:
        base_err = float(np.mean(cv2.absdiff(cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY), ref_gray)))
        eased, flow_median = flow_refine(ref_gray, warped, cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY))
        eased_err = float(np.mean(cv2.absdiff(cv2.cvtColor(eased, cv2.COLOR_BGR2GRAY), ref_gray)))

        if eased_err < base_err:
            warped = eased

        if min(base_err, eased_err) > 14.0:
            strong, strong_median = flow_refine(ref_gray, warped, cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY), strong=True)
            strong_err = float(np.mean(cv2.absdiff(cv2.cvtColor(strong, cv2.COLOR_BGR2GRAY), ref_gray)))
            if strong_err < min(base_err, eased_err):
                warped = strong
                flow_median = max(flow_median, strong_median)

    # ── stage 4: deflicker ────────────────────────────────────────────────
    luma_gain, luma_offset = 1.0, 0.0
    if PHOTO_ENABLED:
        warped, luma_gain, luma_offset = photometric_match(ref, warped)

    ok = cv2.imwrite(out_path, warped, [int(cv2.IMWRITE_JPEG_QUALITY), 90])

    if not ok:
        print(json.dumps({"aligned": False, "reason": "write failed"}))
        sys.exit(1)

    shift = transform @ np.array([width / 2, height / 2, 1.0])
    offset_px = float(np.hypot(shift[0] - width / 2, shift[1] - height / 2))

    print(json.dumps({
        "aligned": True,
        "engine": engine,
        "transform": chosen,
        "error": round(scored[0][0], 2),
        "candidate_errors": {name: round(err, 2) for err, name, *_ in scored},
        "matches": len(matches),
        "inliers": inliers,
        "scale": round(scale, 3),
        "rotation_deg": round(float(np.degrees(np.arctan2(transform[0, 1], transform[0, 0]))), 2),
        "center_offset_px": round(offset_px, 1),
        "ecc_cc": round(ecc_cc, 4),
        "flow_median_px": round(flow_median, 1),
        "luma_gain": luma_gain,
        "luma_offset": luma_offset,
    }))
    sys.exit(0)


if __name__ == "__main__":
    main()
