#!/usr/bin/env python3
"""Detect faces and blur them, in place — privacy for jobsite photos.

    blur_faces.py <image> [<image> ...]

Detection is YuNet (vendored ONNX beside this script), run twice — at
native size and at 2x — because a crew member across a room is a 20px
face that native-scale detection misses. Boxes are merged by IoU.

Each face gets an ELLIPTICAL, feathered, strong Gaussian blur — enough
that the person is unidentifiable, soft enough that the photo still reads
as a photo. Images with no faces are left byte-identical (no needless
re-encode). Output: one JSON object with per-file face counts.

This runs on DISPLAY copies only. The `original-*` archive copies are
never touched — they are the evidentiary record, faces and all, and they
keep their EXIF.
"""
import json
import os
import sys

import cv2
import numpy as np

MODEL = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                     "models", "face_detection_yunet_2023mar.onnx")


def detect(gray_bgr, detector, scale):
    h, w = gray_bgr.shape[:2]
    img = cv2.resize(gray_bgr, (int(w * scale), int(h * scale))) if scale != 1.0 else gray_bgr
    detector.setInputSize((img.shape[1], img.shape[0]))
    _, faces = detector.detect(img)
    out = []
    if faces is not None:
        for f in faces:
            x, y, fw, fh, score = f[0] / scale, f[1] / scale, f[2] / scale, f[3] / scale, float(f[14])
            out.append((x, y, fw, fh, score))
    return out


def iou(a, b):
    ax2, ay2, bx2, by2 = a[0] + a[2], a[1] + a[3], b[0] + b[2], b[1] + b[3]
    ix = max(0.0, min(ax2, bx2) - max(a[0], b[0]))
    iy = max(0.0, min(ay2, by2) - max(a[1], b[1]))
    inter = ix * iy
    union = a[2] * a[3] + b[2] * b[3] - inter
    return inter / union if union > 0 else 0.0


def blur_file(path, detector):
    img = cv2.imread(path)
    if img is None:
        return None

    # Two scales: native for normal faces, 2x for distant ones. The 2x pass
    # uses a higher bar — upscaling amplifies texture into false positives.
    found = [f for f in detect(img, detector, 1.0) if f[4] >= 0.60]
    for cand in detect(img, detector, 2.0):
        if cand[4] >= 0.70 and all(iou(cand, kept) < 0.3 for kept in found):
            found.append(cand)

    if not found:
        return 0

    h, w = img.shape[:2]
    mask = np.zeros((h, w), np.float32)
    max_face = 0.0
    for x, y, fw, fh, _ in found:
        # widen 30% — ears, chin, hairline
        cx, cy = x + fw / 2, y + fh / 2
        ax, ay = fw * 0.65, fh * 0.70
        cv2.ellipse(mask, (int(cx), int(cy)), (int(ax), int(ay)), 0, 0, 360, 1.0, -1)
        max_face = max(max_face, fw)

    sigma = max(10.0, max_face / 3.0)
    blurred = cv2.GaussianBlur(img, (0, 0), sigma)
    feather = cv2.GaussianBlur(mask, (0, 0), max(4.0, max_face / 10.0))[:, :, None]
    out = (blurred.astype(np.float32) * feather + img.astype(np.float32) * (1 - feather)).astype(np.uint8)

    tmp = path + ".blur.tmp.jpg"
    if not cv2.imwrite(tmp, out, [int(cv2.IMWRITE_JPEG_QUALITY), 90]):
        return None
    os.replace(tmp, path)

    return len(found)


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "reason": "usage"}))
        return 1

    detector = cv2.FaceDetectorYN.create(MODEL, "", (320, 320), 0.5, 0.3, 5000)

    results = {}
    for path in sys.argv[1:]:
        count = blur_file(path, detector)
        results[os.path.basename(path)] = count  # None = unreadable

    print(json.dumps({"ok": True, "faces": results}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
