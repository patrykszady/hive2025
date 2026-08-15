#!/usr/bin/env python3
"""Align one timelapse frame onto a reference frame.

Pipeline (each stage measurable, each stage safe to skip):

  1. SIFT keypoints + Lowe ratio matches (ORB fallback), then RANSAC
     candidates raced by MEASURED overlap error: similarity (4 DOF), affine,
     and a keystone-capped homography — fit not just on the full match set
     but on up to three peeled MOTION LAYERS. When the camera moved between
     shots, the scene splits into layers (far trees barely rescale; the
     building rescales a lot) and RANSAC's biggest consensus is often the
     wrong one — peeling it away and refitting exposes the building's own
     motion, and the pixels pick the winner.
  2. ECC refinement at half scale — squeezes out the sub-pixel residual the
     keypoint stage leaves behind.
  3. Conservative optical-flow mesh — the parallax cleaner. Dense flow,
     downsampled to a coarse grid, heavily blurred and magnitude-capped.
     Runs ONLY when the global fit's residual is already small: a small
     residual is handheld parallax; a large one is the scene itself changing
     (framed walls, a new deck), which flow would melt rather than align.
  4. Photometric match — the L channel's mean/std eased toward the anchor
     (clamped gain/offset), so different-day lighting doesn't flicker.
  5. The gap — how much of the anchor's canvas the warped shot never reached.
     Past ALIGN_MAX_BORDER the whole alignment is refused. Under it, the gap
     is filled from ALIGN_FILL — the previous aligned frame (or the anchor),
     which lives on the same canvas — with a feathered seam. Real pixels from
     an earlier moment of the same scene; never generated content. With no
     fill source the gap stays honestly black.

The accepted transform must stay MINOR (ALIGN_MAX_SCALE_DELTA /
ALIGN_MAX_ROTATION / ALIGN_MAX_OFFSET): the aligner exists to remove handheld
wobble, and a fit that wants a real re-framing — the crew's stance moved —
keeps the frame as shot instead. Big corrections are a human's call, made in
the studio's manual aligner, which lands here as --apply.

    align_frame.py <reference> <target> <output> [min_inliers]
    align_frame.py --apply <reference> <target> <output> <scale> <tx> <ty>
                   [rot_deg] [preview_width]
    align_frame.py --stats <frame> <frame> ...

--apply warps the target by exactly the given pan/zoom (output px =
scale * input px + t, on the reference's canvas) — no keypoints, no ECC, no
flow. The only automatic touches are the same photometric easing every frame
gets and the gap fill.

Exit codes: 0 aligned · 2 not confident (caller keeps original) · 1 error.
Prints one JSON diagnostics line either way.
"""

import json
import os
import sys

import cv2
import numpy as np

# OPT-IN since Aug 2026: flow is the one stage that can bend a straight
# line, and it has now melted structure three times (wavy studs twice on
# exteriors; softened cabinet edges on an interior the moment a
# near-identical neighbour reference opened its gate). Rigid fits keep
# every line straight by construction — a timelapse reads "seamless" from
# rigid registration, not from rubber-sheeting the residual.
FLOW_ENABLED = os.environ.get("ALIGN_FLOW", "0") == "1"
PHOTO_ENABLED = os.environ.get("ALIGN_PHOTO", "1") != "0"
GEOMETRY_ENABLED = os.environ.get("ALIGN_GEOMETRY", "1") != "0"
# Six numbers "Lm,Ls,am,as,bm,bs": the whole sequence's median LAB profile.
# When set, every frame is eased toward IT instead of toward the reference —
# so the anchor's own quirks (a light flare, say) don't become the standard.
TARGET_STATS = os.environ.get("ALIGN_TARGET", "")
FLOW_CAP_PX = 30.0
FLOW_GRID = 24  # flow is averaged into cells this size (full-res px) then blurred
# Warping a shot onto the anchor's canvas leaves a gap wherever the shot didn't
# reach. There is nothing real to put there, so past this share of the canvas
# the alignment is refused outright: a frame that is a third invented is worse
# than an honestly unaligned one.
MAX_FABRICATED = float(os.environ.get("ALIGN_MAX_BORDER", "0.08"))
# Absolute path of a frame already on the anchor's canvas (previous aligned
# frame, or the anchor itself) whose pixels patch the warp gap.
FILL_PATH = os.environ.get("ALIGN_FILL", "")
# The "minor adjustment" caps for the AUTOMATIC path: past any of these, the
# fit is a re-framing, not wobble removal, and the frame keeps its original.
MAX_SCALE_DELTA = float(os.environ.get("ALIGN_MAX_SCALE_DELTA", "0.08"))
# Absolute plausibility band for a candidate fit — a sanity guard, distinct
# from the "minor adjustment" caps above. Widen it (with the caps) only when
# deliberately registering across a real framing change, e.g. warping a
# full-resolution ORIGINAL straight onto the sequence canvas, where the
# resolution ratio itself lands the scale far from 1.
MIN_PLAUSIBLE_SCALE = float(os.environ.get("ALIGN_MIN_SCALE", "0.7"))
MAX_PLAUSIBLE_SCALE = float(os.environ.get("ALIGN_MAX_SCALE", "1.4"))
MAX_ROTATION_DEG = float(os.environ.get("ALIGN_MAX_ROTATION", "3.0"))
MAX_OFFSET_FRAC = float(os.environ.get("ALIGN_MAX_OFFSET", "0.06"))
# How much extra corner-shift a homography may add over its affine part.
# Tight for routine wobble removal (a keystone that wins by a hair in
# clutter still tilts the scene for no reason); REFRAME mode raises it —
# a photographer who took a step between shots produces a REAL perspective
# change, and matching the previous frame through it needs the projective
# terms ("it's ok if the perspective changes, it should be matched with
# the previous frame"). A homography keeps straight lines straight.
MAX_KEYSTONE_PX = float(os.environ.get("ALIGN_MAX_KEYSTONE", "12"))
# Longest edge the keypoint detector may work at. SIFT memory grows with the
# PIXEL COUNT (~215MB per megapixel here), and a 24MP original needed 5.3GB —
# the kernel SIGKILLed it. Set ABOVE the common phone original (3024-4032 on
# the long edge is untouched): shrinking a normal frame measurably costs
# matches (2400px lost 10 inliers on a hard frame and swung the rotation
# nearly 4 degrees). Only genuinely oversized shots are reduced.
WORK_MAX_PX = int(os.environ.get("ALIGN_WORK_MAX_PX", "3400"))


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


def flow_refine(ref_gray, warped, warped_gray):
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

    # Coarse grid + wide blur: keep only the low-frequency component.
    grid = FLOW_GRID
    cap = FLOW_CAP_PX
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


def photometric_match(ref, image, mask=None):
    """Ease the frame's full LAB stats toward the target profile (clamped).

    Target = the sequence median when ALIGN_TARGET is set (color-matching the
    whole sequence to its collective look), else the reference frame. All
    three channels: L evens out exposure, a/b evens out color cast — the
    difference between frames "navigating nicely" and pulsing warm/cool.

    mask selects which pixels the frame's OWN stats are measured on — pass
    the warp coverage so the black gap (L=0, not photo) doesn't drag the
    measured mean down and pin the gain/offset at their clamps, grading the
    real content by how much border the warp left instead of by its light.
    The correction still applies to the whole canvas; the gap gets patched
    over afterwards anyway.
    """
    if TARGET_STATS:
        target = [float(v) for v in TARGET_STATS.split(",")]
    else:
        target = lab_stats(ref)

    img_lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB).astype(np.float32)
    measured = img_lab[mask] if mask is not None and mask.any() else img_lab.reshape(-1, 3)

    # L does the heavy lifting (exposure/flare). a/b stay GENTLE: measured on
    # real sequences, color casts barely drift — aggressive matching there
    # created differences instead of removing them.
    clamps = [(0.85, 1.18, 20.0), (0.96, 1.04, 5.0), (0.96, 1.04, 5.0)]
    gains = []

    for channel, (lo, hi, max_off) in enumerate(clamps):
        t_mean, t_std = target[channel * 2], target[channel * 2 + 1]
        mean = float(measured[:, channel].mean())
        std = float(measured[:, channel].std())

        gain = float(np.clip(t_std / max(std, 1e-3), lo, hi))
        offset = float(np.clip(t_mean - mean * gain, -max_off, max_off))
        img_lab[:, :, channel] = np.clip(img_lab[:, :, channel] * gain + offset, 0, 255)
        gains.append(round(gain, 3))

    return cv2.cvtColor(img_lab.astype(np.uint8), cv2.COLOR_LAB2BGR), gains[0], 0.0



def cover_fit(matrix, perspective, source_shape, canvas_shape, limit=0.12):
    """Nudge the zoom up just enough that the SOURCE covers the whole canvas.

    A warp can leave a thin gap even when the source has pixels to spare —
    a degree of rotation, a little pan, and a corner falls short. Filling
    that from a neighbour imports another moment of the scene, which on a
    "before" frame means cabinets and floor protection appearing in an empty
    room. Cropping ~1% tighter instead costs nothing visible and invents
    nothing at all.

    Returns (matrix, factor) — factor 1.0 when no nudge was needed or when
    the gap is too big to close within `limit`.
    """
    h, w = canvas_shape
    sh, sw = source_shape
    centre = np.array([w / 2.0, h / 2.0])

    def gap_of(m):
        probe = np.full((sh, sw), 255, np.uint8)
        if perspective:
            cov = cv2.warpPerspective(probe, m, (w, h), flags=cv2.INTER_NEAREST,
                                      borderMode=cv2.BORDER_CONSTANT)
        else:
            cov = cv2.warpAffine(probe, m[:2], (w, h), flags=cv2.INTER_NEAREST,
                                 borderMode=cv2.BORDER_CONSTANT)
        return float((cov == 0).mean())

    full = matrix if matrix.shape[0] == 3 else np.vstack([matrix, [0, 0, 1]])

    if gap_of(full) <= 0.0:
        return matrix, 1.0

    # ONLY when the source has pixels to spare — a big original being cropped
    # in. A source no larger than the canvas has no overflow, so zooming to
    # close its gap would silently re-crop the framing that was asked for;
    # that gap is honest and belongs to patch_gap.
    linear = np.array(full, dtype=np.float64)[:2, :2]
    span = float(np.sqrt(abs(np.linalg.det(linear))))
    if sw * span < w * 1.02 or sh * span < h * 1.02:
        return matrix, 1.0

    def scaled(f):
        about = np.array([[f, 0, centre[0] * (1 - f)],
                          [0, f, centre[1] * (1 - f)],
                          [0, 0, 1]], np.float64)
        return about @ full

    # Smallest zoom-up that closes the gap; give up rather than crop hard.
    lo, hi = 1.0, 1.0 + limit
    if gap_of(scaled(hi)) > 0.0:
        return matrix, 1.0

    for _ in range(12):
        mid = (lo + hi) / 2
        if gap_of(scaled(mid)) > 0.0:
            lo = mid
        else:
            hi = mid

    out = scaled(hi)

    return (out if matrix.shape[0] == 3 else out[:2]).astype(matrix.dtype), float(hi)


def patch_gap(warped, gap):
    """Patch the canvas the warp never reached, with the same scene from an
    earlier moment when a fill frame is given — it already lives on the
    anchor's canvas, so its pixels drop straight in. The fill is first
    GRADED toward this frame's own palette (the neighbor may be a different
    season and light entirely) and softly blurred, and the seam is a wide
    gradient — a fill that keeps its own colors against a hard edge reads
    as a visible band at the frame border. No fill source → honest black.

    Returns (patched image, fill basename or None).
    """
    fill = cv2.imread(FILL_PATH) if FILL_PATH else None

    if fill is None or fill.shape[:2] != warped.shape[:2] or not gap.any():
        warped[gap] = 0

        return warped, None

    # The fill is registered GLOBALLY but the band lives at the frame edge —
    # peak parallax — so tape lines and paper edges crossing the seam can
    # sit locally offset and dead-end at the joint. Measure the residual
    # translation on the RING of real photo just inside the gap (what the
    # band must continue from) via masked phase correlation, and shift the
    # fill by it. Rigid, capped, and nothing bends.
    ring = (cv2.dilate(gap.astype(np.uint8), np.ones((121, 121), np.uint8)) > 0) & ~gap
    if ring.sum() > 5000:
        fg = cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY).astype(np.float32)
        lg = cv2.cvtColor(fill, cv2.COLOR_BGR2GRAY).astype(np.float32)
        m = ring.astype(np.float32)
        (dx, dy), resp = cv2.phaseCorrelate(fg * m, lg * m)
        if resp > 0.03 and abs(dx) <= 40 and abs(dy) <= 40:
            fill = cv2.warpAffine(fill, np.float32([[1, 0, -dx], [0, 1, -dy]]),
                                  (fill.shape[1], fill.shape[0]), flags=cv2.INTER_LINEAR,
                                  borderMode=cv2.BORDER_REPLICATE)

    # Grade the fill toward the frame it patches: per-channel LAB stats
    # measured on the SEAM RING (both sides of the joint), not the whole
    # frame — the band must continue the paper beside it, and a whole-frame
    # average once left band paper visibly grayer than the paper it touched.
    frame_lab = cv2.cvtColor(warped, cv2.COLOR_BGR2LAB).astype(np.float64)
    fill_lab = cv2.cvtColor(fill, cv2.COLOR_BGR2LAB).astype(np.float64)
    stat_zone = ring if ring.sum() > 5000 else ~gap
    for c, (lo, hi, cap) in enumerate([(0.75, 1.35, 34.0), (0.92, 1.09, 12.0), (0.92, 1.09, 12.0)]):
        f_mean, f_std = fill_lab[:, :, c][stat_zone].mean(), fill_lab[:, :, c][stat_zone].std()
        t_mean, t_std = frame_lab[:, :, c][stat_zone].mean(), frame_lab[:, :, c][stat_zone].std()
        gain = float(np.clip(t_std / max(f_std, 1e-3), lo, hi))
        offset = float(np.clip(t_mean - f_mean * gain, -cap, cap))
        fill_lab[:, :, c] = fill_lab[:, :, c] * gain + offset
    fill = cv2.cvtColor(np.clip(fill_lab, 0, 255).astype(np.uint8), cv2.COLOR_LAB2BGR)

    # Softened copy for the seam neighbourhood, prepared here but applied
    # DEPTH-WEIGHTED below: blur across the whole patch was right when fills
    # were thin slivers, but a re-anchored close-up can carry a 25% border
    # band, and a quarter of the frame in σ3 blur reads as smeared furniture,
    # not context. Deep fill is real photo from a neighbouring moment and
    # stays crisp; the blur's only job is to hide the joint.
    soft = cv2.GaussianBlur(fill, (0, 0), 3.0)

    # Two leaks past the coverage mask read as dark bars at the edges:
    # the warp's INTER_LINEAR fringe, and the flow stage dragging the
    # border (and its blended fringe — dark GRAY, not pure black) up to
    # ~FLOW_CAP_PX inward AFTER coverage was measured. Catch them by
    # geodesic growth: starting from the true gap, flood into CONNECTED
    # dark pixels. Dark content in the middle of the photo has no dark
    # path to the gap and is never touched.
    dark_or_gap = ((cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY) < 45) | gap).astype(np.uint8)
    region = gap.astype(np.uint8)
    kernel3 = np.ones((3, 3), np.uint8)
    prev = -1
    for _ in range(int(FLOW_CAP_PX * 2)):
        region = cv2.dilate(region, kernel3) & dark_or_gap
        total = int(region.sum())
        if total == prev:
            break
        prev = total
    patch = cv2.dilate(region, np.ones((7, 7), np.uint8)) > 0

    # Ease the softened copy in only within ~160px of the seam (depth 0 at
    # the joint, fully crisp past it) — see the note above `soft`.
    depth = cv2.distanceTransform(patch.astype(np.uint8), cv2.DIST_L2, 5)
    near_seam = np.clip(1.0 - depth / 160.0, 0.0, 1.0).astype(np.float32)[:, :, None]
    fill = (soft.astype(np.float32) * near_seam
            + fill.astype(np.float32) * (1.0 - near_seam)).astype(np.uint8)

    # Alpha 1 deep inside the gap, easing to 0 over a wide (~100px) ramp
    # inside the real photo. The fill comes from a different moment of the
    # scene, so a thin feather reads as a hard seam line at the frame edge —
    # a broad gradient reads as a soft vignette instead.
    alpha = cv2.GaussianBlur(cv2.dilate(patch.astype(np.uint8), np.ones((41, 41), np.uint8)).astype(np.float32), (0, 0), 30.0)
    alpha = np.maximum(alpha, patch.astype(np.float32))[:, :, None]
    warped = (fill.astype(np.float32) * alpha
              + warped.astype(np.float32) * (1.0 - alpha)).astype(np.uint8)

    return warped, os.path.basename(FILL_PATH)


def apply_mode(argv):
    """--apply <ref> <target> <out> <scale> <tx> <ty> [rot_deg] [preview_width]

    The studio's manual aligner: a human panned / zoomed / TURNED the frame
    over the anchor by eye, and this applies EXACTLY that. Scale and rotation
    pivot about the IMAGE CENTRE (matching the modal's transform-origin), then
    the pan is added:

        out = s * R * (in - C) + C + t,  C = the target's own centre

    No keypoints, no ECC, no flow, no caps: the framing is the human's call.

    preview_width is the width of the copy the human actually aligned against
    (the 1920px sequence frame). Passing it lets this warp the full-resolution
    ORIGINAL instead while producing the identical framing: every length is
    scaled by k = preview_width / target_width. More source pixels means a
    zoomed-in crop stays sharp and the turn's corners are covered by real
    overflow rather than gap. (The original holds more RESOLUTION, not more
    field of view — corners only fill once the frame is zoomed past ~1, and
    whatever is still short gets the honest fill below.)
    """
    if len(argv) not in (6, 7, 8):
        print(json.dumps({"aligned": False, "reason": "usage"}))
        sys.exit(1)

    ref = cv2.imread(argv[0])
    target = cv2.imread(argv[1])
    out_path = argv[2]

    if ref is None or target is None:
        print(json.dumps({"aligned": False, "reason": "unreadable input"}))
        sys.exit(1)

    try:
        scale, tx, ty = (float(v) for v in argv[3:6])
        rotation = float(argv[6]) if len(argv) >= 7 else 0.0
        preview_width = float(argv[7]) if len(argv) == 8 else 0.0
    except ValueError:
        print(json.dumps({"aligned": False, "reason": "usage"}))
        sys.exit(1)

    if not (0.2 <= scale <= 5.0):
        print(json.dumps({"aligned": False, "reason": "implausible transform", "scale": scale}))
        sys.exit(1)

    height, width = ref.shape[:2]
    target_h, target_w = target.shape[:2]

    # k maps the human's preview lengths onto this source's pixels — 1.0 when
    # the source IS what they aligned against.
    k = (preview_width / target_w) if preview_width > 0 else 1.0

    theta = np.radians(rotation)
    cos_t, sin_t = float(np.cos(theta)), float(np.sin(theta))
    linear = np.array([[cos_t, -sin_t], [sin_t, cos_t]], np.float64) * scale
    # Centre of the frame the human saw, in preview pixels.
    centre = np.array([k * target_w / 2.0, k * target_h / 2.0])
    shift = centre - linear @ centre + np.array([tx, ty])

    matrix = np.float32([
        [linear[0, 0] * k, linear[0, 1] * k, shift[0]],
        [linear[1, 0] * k, linear[1, 1] * k, shift[1]],
    ])

    matrix, cover_scale = cover_fit(matrix, False, target.shape[:2], (height, width),
                                    limit=0.02 if (FILL_PATH and os.path.isfile(FILL_PATH)) else 0.12)

    warped = cv2.warpAffine(target, matrix, (width, height),
                            flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_CONSTANT)
    covered = cv2.warpAffine(np.full(target.shape[:2], 255, np.uint8), matrix, (width, height),
                             flags=cv2.INTER_NEAREST, borderMode=cv2.BORDER_CONSTANT)

    luma_gain, luma_offset = 1.0, 0.0
    if PHOTO_ENABLED:
        warped, luma_gain, luma_offset = photometric_match(ref, warped, covered != 0)

    gap = covered == 0
    fabricated = float(gap.mean())
    warped, filled_from = patch_gap(warped, gap)

    if not cv2.imwrite(out_path, warped, [int(cv2.IMWRITE_JPEG_QUALITY), 90]):
        print(json.dumps({"aligned": False, "reason": "write failed"}))
        sys.exit(1)

    print(json.dumps({
        "aligned": True,
        "manual": True,
        "scale": round(scale, 4),
        "rotation_deg": round(rotation, 2),
        "source_ratio": round(k, 4),
        "cover_scale": round(cover_scale, 4),
        # The transform in the manual aligner's own terms, so reopening the
        # modal starts from exactly what this produced.
        "preview_transform": {
            "scale": round(scale, 6),
            "rotation": round(rotation, 4),
            "tx": round(tx, 3),
            "ty": round(ty, 3),
            "preview_width": round(k * target_w, 1),
        },
        "tx": round(tx, 1),
        "ty": round(ty, 1),
        "fabricated": round(fabricated, 4),
        "filled_from": filled_from,
        "luma_gain": luma_gain,
        "luma_offset": luma_offset,
    }))
    sys.exit(0)


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == "--stats":
        stats_mode(sys.argv[2:])

    if len(sys.argv) >= 2 and sys.argv[1] == "--apply":
        apply_mode(sys.argv[2:])

    if len(sys.argv) < 4:
        print(json.dumps({"aligned": False, "reason": "usage"}))
        sys.exit(1)

    ref_path, target_path, out_path = sys.argv[1:4]
    min_inliers = int(sys.argv[4]) if len(sys.argv) > 4 else 25
    # Reframe mode warps the full-resolution original; this is the width of
    # the copy a human would align against, used only to report the
    # transform in preview terms.
    preview_width_hint = float(os.environ.get("ALIGN_PREVIEW_WIDTH", "0")) or None

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

    # Detect on a size-capped copy. SIFT's scale-space pyramid is what costs
    # the memory, and it grows with the PIXELS, not with the useful detail: a
    # 24MP phone original (5712x4284) peaked near 6GB and the kernel SIGKILLed
    # the process mid-frame, while the same shot at 2400px matches just as
    # well against a 1920px canvas. Only the KEYPOINTS come from the small
    # copy — their coordinates are scaled straight back to full resolution
    # below, so every fit, warp and gap measurement downstream still happens
    # in full-resolution target space and nothing is sampled at lower quality.
    work_scale = 1.0
    detect_gray = target_gray

    if WORK_MAX_PX > 0 and max(target_gray.shape[:2]) > WORK_MAX_PX:
        work_scale = WORK_MAX_PX / float(max(target_gray.shape[:2]))
        detect_gray = cv2.resize(target_gray, None, fx=work_scale, fy=work_scale,
                                 interpolation=cv2.INTER_AREA)

    ref_kp, target_kp, matches, engine = detect_and_match(ref_gray, detect_gray)

    if detect_gray is not target_gray:
        del detect_gray

    if len(matches) < min_inliers:
        fail("too few matches", matches=len(matches), engine=engine)

    src = np.float32([target_kp[m.queryIdx].pt for m in matches]).reshape(-1, 1, 2)
    dst = np.float32([ref_kp[m.trainIdx].pt for m in matches]).reshape(-1, 1, 2)

    # Back to full-resolution target coordinates; everything downstream is
    # unchanged by the detection cap.
    if work_scale != 1.0:
        src /= work_scale

    height, width = ref.shape[:2]

    # ── candidate transforms, judged by MEASURED overlap error ────────────
    # Similarity can't absorb the perspective of standing a step aside; a free
    # homography absorbs it but can keystone visibly. So: fit all three, cap
    # how much extra corner-shift the homography may add over its affine part
    # (imperceptible ≤ 12px at full res), and let the pixels pick the winner.
    #
    # And fit them on more than one MOTION LAYER: when the camera moved
    # between shots, far background (trees, fencing) shifts differently than
    # the building, and RANSAC's biggest consensus set can be the wrong one —
    # it once locked a whole sequence onto the tree line at scale 1.06 when
    # the building needed 1.2×. Peeling a layer's consensus away and refitting
    # on what remains exposes the other layers as candidates in the same race.
    def overlap_error(warped_gray_small, ref_gray_small):
        # TRIMMED mean absdiff: the worst quarter of pixels is discarded
        # before averaging. A transient blob — a person mid-frame, a cabinet
        # carried through the shot — is scene CHANGE concentrated in a
        # minority of pixels, and under a plain mean it drowns the signal:
        # every candidate scores the same mediocre number and the wrong one
        # wins on the simplicity tie-break (a delivery crew member filling
        # the middle of one frame shipped a fit 80px off exactly this way).
        # A bad fit doubles every edge across the WHOLE frame, so it still
        # loses decisively in the kept 75%.
        diff = cv2.absdiff(warped_gray_small, ref_gray_small).ravel()
        keep = max(int(diff.size * 0.75), 1)
        return float(np.mean(np.partition(diff, keep - 1)[:keep]))

    small = 0.5
    ref_small = cv2.resize(ref_gray, None, fx=small, fy=small)
    target_small = cv2.resize(target_gray, None, fx=small, fy=small)

    LAYER_MIN_INLIERS = 8

    candidates = []  # (name, matrix, perspective, own_inliers)

    def layer_candidates(layer_src, layer_dst, tag):
        """Fit all three geometries on one layer's matches. Returns the
        similarity consensus mask so the caller can peel this layer away."""
        sim, sim_mask = cv2.estimateAffinePartial2D(
            layer_src, layer_dst, method=cv2.RANSAC, ransacReprojThreshold=5.0, maxIters=5000
        )
        if sim is None:
            return None
        sim_inliers = int(sim_mask.sum())
        if sim_inliers < LAYER_MIN_INLIERS:
            return sim_mask

        scale = float(np.hypot(sim[0, 0], sim[0, 1]))
        if MIN_PLAUSIBLE_SCALE < scale < MAX_PLAUSIBLE_SCALE:
            candidates.append(("similarity" + tag, sim, False, sim_inliers))

        affine, a_mask = cv2.estimateAffine2D(
            layer_src, layer_dst, method=cv2.RANSAC, ransacReprojThreshold=5.0, maxIters=5000
        )
        if affine is not None:
            sx = float(np.hypot(affine[0, 0], affine[1, 0]))
            sy = float(np.hypot(affine[0, 1], affine[1, 1]))
            if (MIN_PLAUSIBLE_SCALE < sx < MAX_PLAUSIBLE_SCALE
                    and MIN_PLAUSIBLE_SCALE < sy < MAX_PLAUSIBLE_SCALE):
                own = int(a_mask.sum()) if a_mask is not None else sim_inliers
                candidates.append(("affine" + tag, affine, False, own))

        homography, h_mask = cv2.findHomography(layer_src, layer_dst, cv2.RANSAC, 5.0)
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
            if keystone <= MAX_KEYSTONE_PX and 0.5 < det < 2.0:
                own = int(h_mask.sum()) if h_mask is not None else sim_inliers
                candidates.append(("homography" + tag, h, True, own))

        return sim_mask

    remaining = np.ones(len(matches), bool)
    for layer in range(3):
        idx = np.flatnonzero(remaining)
        if len(idx) < LAYER_MIN_INLIERS:
            break
        mask = layer_candidates(src[idx], dst[idx], "" if layer == 0 else f"@{layer}")
        if mask is None:
            break
        consensus = mask.ravel().astype(bool)
        if not consensus.any():
            break
        remaining[idx[consensus]] = False

    if not candidates:
        fail("no transform", matches=len(matches), engine=engine)

    scored = []
    for name, matrix, perspective, own_inliers in candidates:
        if perspective:
            small_matrix = np.diag([small, small, 1.0]) @ matrix @ np.diag([1 / small, 1 / small, 1.0])
            trial = cv2.warpPerspective(target_small, small_matrix,
                                        (ref_small.shape[1], ref_small.shape[0]),
                                        flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
        else:
            small_matrix = matrix.copy()
            small_matrix[:, 2] *= small
            trial = cv2.warpAffine(target_small, small_matrix,
                                   (ref_small.shape[1], ref_small.shape[0]),
                                   flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
        scored.append((overlap_error(trial, ref_small), name, matrix, perspective, own_inliers))

    scored.sort(key=lambda row: row[0])

    # Within measurement noise of the best, prefer the simplest geometry: a
    # shear or keystone that wins the mean-absdiff race by a hair (a margin
    # that usually lives in tree and ground clutter) still bends the
    # building visibly; rigid + zoom cannot.
    complexity = {"similarity": 0, "affine": 1, "homography": 2}
    near_best = [row for row in scored if row[0] - scored[0][0] <= 0.5]
    near_best.sort(key=lambda row: (complexity[row[1].split("@")[0]], row[0]))
    winner_err, chosen, matrix, perspective, inliers = near_best[0]

    linear = np.array(matrix, dtype=np.float64)[:2, :2]
    scale = float(np.sqrt(abs(np.linalg.det(linear))))
    affine_rows = np.array(matrix, dtype=np.float64)[:2]
    rotation_deg = float(np.degrees(np.arctan2(affine_rows[0, 1], affine_rows[0, 0])))
    shift = affine_rows @ np.array([width / 2, height / 2, 1.0])
    offset_px = float(np.hypot(shift[0] - width / 2, shift[1] - height / 2))

    # Minor adjustments only: the aligner exists to remove handheld wobble,
    # and warping past these caps re-frames the shot — original pixels traded
    # for crop and invented border. A fit that big is refused outright; the
    # frame keeps its original, and re-framing is the studio's manual
    # aligner's job (a human panning/zooming over the anchor, --apply above).
    if (abs(scale - 1.0) > MAX_SCALE_DELTA
            or abs(rotation_deg) > MAX_ROTATION_DEG
            or offset_px > MAX_OFFSET_FRAC * width):
        fail("beyond minor adjustment",
             scale=round(scale, 3),
             rotation_deg=round(rotation_deg, 2),
             center_offset_px=round(offset_px, 1),
             inliers=inliers)

    # Confidence: a broad consensus is trusted outright. A thin one (the
    # scene changed a lot — mid-renovation, it will) is accepted only when
    # warping MEASURES meaningfully closer to the anchor than leaving the
    # frame alone. And a thin fit never gets the flow stage: thin consensus
    # plus hallucinating flow is exactly how rubber-sheeted frames happen.
    confident = inliers >= min_inliers
    identity_err = None
    if not confident:
        identity_err = overlap_error(
            cv2.resize(target_gray, (ref_small.shape[1], ref_small.shape[0])), ref_small
        )
        if identity_err - winner_err < 3.0:
            fail("too few inliers", matches=len(matches), inliers=inliers,
                 improvement=round(identity_err - winner_err, 2), engine=engine)

    # Carried through every geometric stage alongside the pixels: 255 where the
    # canvas holds real photo, 0 where the warp left a gap. It used to be
    # BORDER_REPLICATE here — the gap filled by smearing the outermost real
    # pixel outward, which reads as content and costs bytes. Now the gap is
    # measured, and what survives is left honestly black.
    def warp_with(m, image, nearest=False):
        flags = cv2.INTER_NEAREST if nearest else cv2.INTER_LINEAR
        if perspective:
            return cv2.warpPerspective(image, m, (width, height), flags=flags,
                                       borderMode=cv2.BORDER_CONSTANT)
        return cv2.warpAffine(image, m[:2] if m.shape[0] == 3 else m, (width, height),
                              flags=flags, borderMode=cv2.BORDER_CONSTANT)

    # A probe warp, used only to estimate the sub-pixel correction below.
    probe = warp_with(matrix, target)

    # ── stage 2: sub-pixel ECC ────────────────────────────────────────────
    ecc_cc = 0.0
    refinement, ecc_cc = ecc_refine(ref_gray, cv2.cvtColor(probe, cv2.COLOR_BGR2GRAY))
    if refinement is not None:
        shift_px = float(np.hypot(refinement[0, 2], refinement[1, 2]))

        # The caps were checked on the keypoint fit; the refinement rides on
        # top of it, so re-check the COMPOSITE — a fit accepted right at the
        # offset cap plus an ECC nudge in the same direction must not sneak
        # the total past what "minor adjustment" promised. Breaching means
        # the refinement is skipped, not the frame refused: the pre-ECC warp
        # already passed. (For a homography winner the affine part stands in,
        # same approximation the caps themselves use.)
        composite = np.vstack([refinement, [0.0, 0.0, 1.0]]) @ np.vstack([affine_rows, [0.0, 0.0, 1.0]])
        comp_scale = float(np.sqrt(abs(np.linalg.det(composite[:2, :2]))))
        comp_rotation = float(np.degrees(np.arctan2(composite[0, 1], composite[0, 0])))
        comp_shift = composite[:2] @ np.array([width / 2, height / 2, 1.0])
        comp_offset = float(np.hypot(comp_shift[0] - width / 2, comp_shift[1] - height / 2))
        within_caps = (abs(comp_scale - 1.0) <= MAX_SCALE_DELTA
                       and abs(comp_rotation) <= MAX_ROTATION_DEG
                       and comp_offset <= MAX_OFFSET_FRAC * width)

        if shift_px < 40 and within_caps:  # a huge ECC "correction" means it diverged — skip
            # COMPOSED into the matrix rather than applied as a second warp:
            # one resample instead of two, and the cover check below then sees
            # the geometry that actually ships.
            matrix = (np.vstack([refinement, [0.0, 0.0, 1.0]])
                      @ (matrix if matrix.shape[0] == 3 else np.vstack([matrix, [0.0, 0.0, 1.0]])))
            if not perspective:
                matrix = matrix[:2]
            matrix = matrix.astype(np.float64)
            # Diagnostics describe what was actually applied.
            scale, rotation_deg, offset_px = comp_scale, comp_rotation, comp_offset

    # Close a thin gap with the source's OWN overflow — a neighbour's pixels
    # are a different moment of the scene. Done last, on the final geometry.
    # The budget is FILL-AWARE: with a fill source on hand, only a zoom the
    # eye cannot see (≤2%) is worth it — an 8% zoom once closed a gap
    # "honestly" and made that one frame lunge closer than both neighbours,
    # which reads far worse than a graded band. With no fill, a zoom up to
    # 12% still beats black.
    matrix, cover_scale = cover_fit(matrix, perspective, target.shape[:2], (height, width),
                                    limit=0.02 if (FILL_PATH and os.path.isfile(FILL_PATH)) else 0.12)

    warped = warp_with(matrix, target)
    covered = warp_with(matrix, np.full(target.shape[:2], 255, np.uint8), nearest=True)


    # ── stage 3: parallax easing ──────────────────────────────────────────
    # ONLY when the global fit already nearly closed the gap. A small residual
    # is what handheld parallax looks like; a large one means the SCENE
    # changed (framed walls, a new deck), and dense flow "tightens" that by
    # melting the new structure into the old — wavy studs, rubber-sheeted
    # roofs. Mean absdiff can't tell those apart, so the gate is on the
    # residual, not on whether flow lowered it.
    flow_median = 0.0
    if FLOW_ENABLED and confident:
        base_err = float(np.mean(cv2.absdiff(cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY), ref_gray)))
        if base_err <= 14.0:
            eased, flow_median = flow_refine(ref_gray, warped, cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY))
            eased_err = float(np.mean(cv2.absdiff(cv2.cvtColor(eased, cv2.COLOR_BGR2GRAY), ref_gray)))

            if eased_err < base_err:
                warped = eased
            else:
                flow_median = 0.0

    # ── stage 4: deflicker ────────────────────────────────────────────────
    luma_gain, luma_offset = 1.0, 0.0
    if PHOTO_ENABLED:
        warped, luma_gain, luma_offset = photometric_match(ref, warped, covered != 0)

    # The retry pass MATCHES against a content-close neighbour, but its
    # caller compares its result with the direct pass, whose error was
    # measured against the anchor — different references, incomparable
    # numbers (a visibly better fit once lost that comparison purely because
    # its reference shared less content). ALIGN_JUDGE names a common
    # yardstick: the same trimmed metric as the race, against the judge
    # image, but HERE — after the photometric easing, on covered pixels only
    # — so a lighting gap between shots doesn't drown the geometry signal
    # the comparison is actually about. The caller sets it on BOTH passes so
    # the two numbers mean the same thing.
    judge_error = None
    judge_path = os.environ.get("ALIGN_JUDGE")
    if judge_path and os.path.isfile(judge_path):
        judge = cv2.imread(judge_path, cv2.IMREAD_GRAYSCALE)
        if judge is not None:
            judge_small = cv2.resize(judge, (ref_small.shape[1], ref_small.shape[0]))
            warped_small = cv2.resize(cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY),
                                      (ref_small.shape[1], ref_small.shape[0]))
            covered_small = cv2.resize(covered, (ref_small.shape[1], ref_small.shape[0]),
                                       interpolation=cv2.INTER_NEAREST) != 0
            if int(covered_small.sum()) > 500:
                diff = cv2.absdiff(warped_small, judge_small)[covered_small].ravel()
                keep = max(int(diff.size * 0.75), 1)
                judge_error = float(np.mean(np.partition(diff, keep - 1)[:keep]))

    # ── stage 5: the gap ──────────────────────────────────────────────────
    # Judged on GEOMETRY only: flow easing shifts pixels by ≤30px locally and
    # can't meaningfully change how much of the canvas the photo reached.
    gap = covered == 0
    fabricated = float(gap.mean())

    # A gap that will be PATCHED with real earlier pixels is far less costly
    # than one left black — with a fill source on hand, tolerate more of it.
    has_fill = bool(FILL_PATH) and os.path.isfile(FILL_PATH)
    limit = MAX_FABRICATED * 2.5 if has_fill else MAX_FABRICATED

    if fabricated > limit:
        fail("border too large",
             fabricated=round(fabricated, 4),
             limit=limit,
             transform=chosen,
             error=round(winner_err, 2),
             inliers=inliers)

    # What's left is under the limit but isn't photo from THIS shot.
    warped, filled_from = patch_gap(warped, gap)

    ok = cv2.imwrite(out_path, warped, [int(cv2.IMWRITE_JPEG_QUALITY), 90])

    if not ok:
        print(json.dumps({"aligned": False, "reason": "write failed"}))
        sys.exit(1)

    # Same parameterisation the manual aligner speaks, so a human can open
    # the modal on top of whatever the automatic pass decided: scale/turn
    # about the frame's centre, then pan. Measured in PREVIEW pixels — the
    # warp may have sampled a larger original.
    src_h, src_w = target.shape[:2]
    preview_w = float(preview_width_hint or src_w)
    ratio = preview_w / src_w
    linear_preview = np.array(matrix, dtype=np.float64)[:2, :2] / ratio
    p_scale = float(np.sqrt(abs(np.linalg.det(linear_preview))))
    p_rot = float(np.degrees(np.arctan2(linear_preview[1, 0], linear_preview[0, 0])))
    centre_prev = np.array([preview_w / 2.0, preview_w * src_h / src_w / 2.0])
    rot_rad = np.radians(p_rot)
    sr = p_scale * np.array([[np.cos(rot_rad), -np.sin(rot_rad)],
                             [np.sin(rot_rad), np.cos(rot_rad)]])
    pan = np.array(matrix, dtype=np.float64)[:2, 2] - (np.eye(2) - sr) @ centre_prev

    print(json.dumps({
        "aligned": True,
        "engine": engine,
        "transform": chosen,
        "preview_transform": {
            "scale": round(p_scale, 6),
            "rotation": round(p_rot, 4),
            "tx": round(float(pan[0]), 3),
            "ty": round(float(pan[1]), 3),
            "preview_width": round(preview_w, 1),
        },
        "error": round(winner_err, 2),
        "judge_error": round(judge_error, 2) if judge_error is not None else None,
        "candidate_errors": {name: round(err, 2) for err, name, *_ in scored},
        "matches": len(matches),
        "inliers": inliers,
        "low_confidence": not confident,
        "identity_error": round(identity_err, 2) if identity_err is not None else None,
        "scale": round(scale, 3),
        "rotation_deg": round(rotation_deg, 2),
        "center_offset_px": round(offset_px, 1),
        "cover_scale": round(cover_scale, 4),
        "work_scale": round(work_scale, 4),
        "ecc_cc": round(ecc_cc, 4),
        "fabricated": round(fabricated, 4),
        "filled_from": filled_from,
        "flow_median_px": round(flow_median, 1),
        "luma_gain": luma_gain,
        "luma_offset": luma_offset,
    }))
    sys.exit(0)


if __name__ == "__main__":
    main()
