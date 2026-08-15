---
name: timelapse-alignment
description: "Aligns, color-harmonizes, and repairs project timelapse frames. Activates when working on timelapse alignment, frame registration, the aligner scripts (align_frame.py, harmonize_frames.py), imported/HEIC frames, sun flares, stitch seams, tone matching between frames, or when the user says a timelapse frame doesn't match, is skewed, tilted, cropped wrong, or the wrong color."
metadata:
  author: hive
  proven-on: "Back 1 timelapse, project 364, Aug 2026"
---

# Timelapse Frame Alignment & Harmonization

Everything learned making the Back 1 sequence (project 364) seamless: an
imported April "before" HEIC from a different vantage, a July frame the
auto-aligner refused, two frames melted by optical flow, a sun flare, hard
fill seams, and mismatched color/tone. The pipeline handles the routine
cases automatically; this file is the playbook for the hard ones.

## What the pipeline already does (applies to every timelapse)

- **Auto-align on upload** (`AlignTimelapseFrame` → `scripts/align_frame.py`):
  SIFT + sequential motion-layer peeling (RANSAC's biggest consensus is often
  the far background — trees barely rescale; peel it away and refit), then
  similarity/affine/homography candidates raced by measured overlap error
  with a simplicity tie-break. The race metric is a **TRIMMED mean** (worst
  25% of pixels discarded): a transient blob — a delivery crew member
  mid-frame, a carried cabinet — is scene change concentrated in a minority
  of pixels, and under a plain mean it flattened the race (all candidates
  within 0.1) so a wrong motion-layer fit shipped 80px off; a bad fit
  doubles every edge and still loses in the kept 75%. Calibration: good fits
  ≈ 3–8, fits worth a second opinion ≥ 9 (`RETRY_ERROR`), hopeless ≥ 15.
  **Minor-adjustment caps**: scale ±8%,
  rotation 3°, center offset 6% of width — the aligner removes handheld
  wobble ONLY. A fit beyond the caps means the stance moved; the frame keeps
  its original and a human decides (modal or playbook below).
- **Every frame races TWO references, judged on the TRANSITION**: the
  anchor pass keeps the sequence globally pinned; a second pass references
  `nearestAlignedFrameFor()` — nearest in EITHER direction, ties toward the
  anchor ("older only" left the sequence's first frame, content-farthest
  from the anchor, with no fallback — its twin sits on the anchor side).
  BOTH passes report `judge_error` via `ALIGN_JUDGE=<nearest neighbour's
  aligned copy>` — same trimmed metric, measured after photometric easing,
  covered pixels only — because what playback shows is each frame against
  the frame BESIDE it: anchor-judged verdicts once kept an anchor fit
  whose 2% scale error, invisible absolutely, made three near-identical
  frames pulse against each other. Raw `error` values are incomparable
  across references (a visibly better neighbour fit once lost by 0.3
  because a 3-hour lighting gap drowned the geometry signal). Re-anchor
  chains dispatch in **distance-from-anchor order** (`setAlignmentAnchor`),
  so the neighbour a frame needs is already registered when its turn comes.
- **Optical flow is OFF by default** (`ALIGN_FLOW=1` to opt in). It is the
  ONE stage that can bend a straight line, and it earned the demotion three
  times: melted studs twice on exteriors, then softened cabinet edges on an
  interior the moment a near-identical neighbour reference opened its
  residual gate (≤14, plain mean — trivially satisfied between same-session
  shots). Rigid similarity/homography warps keep every line straight BY
  CONSTRUCTION; the ~10px it might have "fixed" is usually parallax from a
  handheld step sideways, which flow can only hide by rubber-sheeting the
  nearest cabinet. Seamless = rigid registration, not local warping.
- **Anchor**: `ProjectTimelapse.anchor_frame_id`, set by the viewfinder
  button; re-anchoring clears every aligned copy and re-chains the whole
  sequence with `AlignTimelapseFrame(id, reframe: true)`. REFRAME MODE is the
  deliberate-change path and differs from a routine capture in three ways:
  the minor-adjustment caps lift (scale ±0.60, rot 5, offset 0.45, band
  0.45–1.60), the warp samples the full-res ORIGINAL (a frame shot farther
  away must zoom IN, and upscaling the 1920px copy shows), and the border
  budget rises to 0.20 (0.50 with a fill) because a frame shot CLOSER than
  the anchor cannot cover its canvas — the missing band comes from the
  anchor's canvas. BOTH directions are normal and both are proven on
  project 373: anchor on a TIGHTER frame => everyone else crops in (zero
  border); anchor on a WIDER frame => closer frames shrink to ~0.52 and the
  outer ~30% fills from the anchor, which reads as one room because the
  fill is graded, seam-softened and wide-feathered. Past half the canvas the
  result is more fill than frame, so it stays honestly as shot.
  The anchor defines the canvas: its own framing/perspective, full field of
  view, uncropped — everything else is matched to it.
- **Manual aligner modal** (arrows button, Admin): pan / zoom / TURN onion
  skin over the anchor. `--apply <ref> <target> <out> <scale> <tx> <ty>
  [rot_deg] [preview_width]`. Four rules hold it together:
  (1) scale and turn pivot about the image CENTRE (`transform-origin: 50%
  50%`), and apply_mode composes `out = s*R*(in - C) + C + t` to match —
  a corner pivot swings the frame out of view;
  (2) the zoom range is 0.2-8 (slider and server bound alike) — a re-frame
  onto a close anchor runs past 2x, and a slider that cannot REACH a frame's
  stored fit snaps it backwards the moment it is touched;
  (2b) cursor-anchored zoom under a centre pivot is
  `t += (1-k)(cursor - centre - t)`, which is rotation-independent;
  (3) the warp samples the full-res ORIGINAL while the human aligns against
  the 1920px copy — `preview_width` maps their numbers onto the extra pixels
  (k = preview_width / source_width). More RESOLUTION, not more field of
  view: a turn's corners fill from overflow only once zoomed past ~1.09 for
  5 degrees, measured; below that the gap fill covers it;
  (4) **Use as anchor** sends the modal's CURRENT composition: the human hits
  "Reset to original" for the untouched shot, or levels/crops first, and that
  composition is rendered from the original onto the canvas, stored, and left
  alone while every other frame re-processes onto it (the anchor is excluded
  from the chain so nothing re-derives it). `AlignTimelapseFrame` therefore
  references `anchor->display_path`, never `anchor->path` — matching a
  composed anchor's raw copy would register the sequence onto a framing the
  anchor no longer shows;
  (5) the modal OPENS ON `frames.align_transform` — the fit the sequence
  currently plays — not at 1:1. Both the automatic pass and --apply report
  `preview_transform` and the caller persists it. Without this the human
  re-does the zoom by hand AND a 1:1 turn has no overflow to reveal.
  After saving, the frame is re-graded (`HarmonizeTimelapseFrameColor`) so a
  hand-aligned frame is not the one that stands out tonally.
- **HEIC import**: converted at the door (aligner venv python + pillow-heif,
  EXIF/shot-date preserved, orientation baked; ImageMagick fallback loses
  metadata). Uploads append; reorder via edit mode.
- **One card open at a time** on the images page: each card dispatches
  `images-card-opened` when its Alpine `open` turns true and collapses itself
  on anyone else's. Two rules keep it stable: the server-side `$openCardKey`
  must NOT depend on which card the camera is in (a changed `x-data` string
  makes Livewire re-init the Alpine component, snapping every card back to
  the server's opinion on each round trip), and the collapse handler releases
  the camera only when a live `<video>` is still inside THAT card — a
  render-time flag closes the camera that just moved to another card.
- **Camera lives INSIDE the card it shoots into** (`_camera.blade.php`
  included by `_collection-card` when that collection is selected) — no
  separate camera panel, no "shooting into" badge; the header button toggles
  `selectCollection` / `closeCamera`. Two traps this created, both fixed and
  both easy to reintroduce: (1) the card's `wire:key` must NOT include the
  frame count, or every capture replaces the card subtree and kills the
  wire:ignore MediaStream — key by collection id alone and let Livewire
  morph; (2) the card is morphed, so Alpine keeps its old `open` — the
  camera partial's root carries `x-init="open = true"` (plain div, so it
  evaluates in the CARD's scope) to expand a collapsed card.
- **Who may curate**: `ProjectPolicy::manageImages` — Admins of the vendor
  that OWNS the project (`belongs_to_vendor_id`, read via
  `getRoleForVendor()`, so multi-vendor users are judged where it matters).
  Viewing/shooting stays open to anyone who can view the project. The
  component exposes `#[Computed] canManageImages` for visibility; every
  mutating method re-authorizes server-side. Never gate on the generic
  `vendor_role === 'Admin'` here — that lets a collaborating vendor's admin
  rewrite someone else's project history.
- **Edit mode** (pencil button on the timelapse card, owning-vendor Admin):
  the ONE place
  frames can be reordered (side chevrons, `moveFrame` renumbers 1..n),
  soft-deleted, re-anchored, or manually aligned — and the only place the
  card-level Select and Delete appear for a timelapse. Deletion is SOFT at
  both levels: `project_timelapses.deleted_at` (frames and files untouched;
  restore with `ProjectTimelapse::withTrashed()->find(id)->restore()`) and
  `project_timelapse_frames.deleted_at` (files kept until forceDelete).
  Thumbnails carry no management chrome outside edit mode; photo albums
  keep their own taker-or-admin delete and always-on Select.
- **Gap fill** (`patch_gap`): fill pixels come from the nearest **PRECEDING
  aligned frame with a fully-real canvas** (`precedingCrispFillFor()`,
  fabricated ≈ 0 in its stored fit), falling back to the anchor's canvas.
  They get **graded to the frame they patch** (per-channel LAB, loose
  clamps), blurred **only within ~160px of the seam** (σ3, depth-weighted —
  deep fill stays crisp), and blended over a ~100px gradient. Thin feathers
  read as seams. The sourcing rules are scar tissue, in order learned:
  chaining off the previous aligned frame compounds (N patches from N−1's
  patch band, each hop re-grading and re-blurring until the ring is mush —
  "we distort items in the frames too much"); whole-patch σ3 blur smears
  furniture once reframe fills reach a 25% band; "nearest crisp in either
  direction" pasted a later frame's delivery crew member into six borders;
  and the anchor-always rule put a cabinet into a frame shot before that
  cabinet existed — **a fill may show the past, never the future**. On a
  re-anchor, the pre-anchor side processes anchor-outward (no earlier frame
  is aligned yet, so those fills fall back to the anchor); frames whose
  band matters get a second `--apply` refill pass from the nearest
  preceding crisp frame once it exists. `cover_fit` runs first: a source
  with real overflow (a zoomed-in original) closes a thin gap by zooming up
  ≤12% about the canvas centre — its own pixels beat any fill.
- **Color harmonize is AUTOMATIC**: `AlignTimelapseFrame` chains
  `HarmonizeTimelapseFrameColor` after every alignment attempt (aligned or
  kept-original; the anchor self-skips) — no button. The
  `HarmonizeTimelapseColors` orchestrator remains for bulk regrades via
  tinker. Script: `scripts/harmonize_frames.py`, grading toward the ANCHOR's
  display copy. Honest-black warp borders are excluded from the grade and
  restored after it (a graded border reads as a gray halo). Stages: MKL color transfer (L wholesale; a/b chroma
  p95-limited to ±8 — raw MKL ROTATES hues and tinted a spring frame salmon
  from the anchor's red sheathing), then a slope-clamped L tone curve (CDF
  matching — fixes "sky lighter but house darker", which no linear gain
  can), then clamped per-channel LAB stats. The final ease back toward the
  frame as shot is SPLIT by channel: L keeps 45% of each frame's own light
  (HARMONIZE_STRENGTH 0.55 — days genuinely brighten and fade), but chroma
  converges hard (HARMONIZE_STRENGTH_CHROMA 0.90) — a material is the same
  object in every frame, and WB drift that shifts pink floor paper between
  salmon and rose reads as flicker ("the pink paper and blue tape needs to
  match"). **Whole-image only. Never region masks**: a sky-mask version
  washed over tree canopies and was rejected outright ("it crops things
  like trees").
- Aligned copies are derived: `aligned-*.jpg` + `touch()` (updated_at is the
  immutable-URL cache buster). Originals (`original-*`) are never modified.
- **Faces are blurred on every DISPLAY copy** (`App\Services\FaceBlur` →
  `scripts/blur_faces.py`, YuNet ONNX vendored in `scripts/models/`). Called
  wherever a display file is written: importer, `ProcessTimelapseFrame`,
  `AlignTimelapseFrame`, manual align, composed anchor — the last three
  matter because they sample the UNBLURRED archive original, so a warped
  frame arrives with the face back. Detection runs at 1x (score ≥0.60) and
  2x (≥0.70, IoU-deduped) because a crew member across a room is a 20px
  face. Blur is an ellipse at σ = face/3, feathered. **`original-*` is never
  passed to it** — that is the evidentiary record, faces and EXIF intact.
  Faceless files are left byte-identical (no re-encode), and a blurred face
  is no longer detected, so `php artisan images:blur-faces [--project=N]` is
  safely re-runnable; it `touch()`es what it rewrites so cached URLs break.
- **The archive original has its own secret address**:
  `/timelapse/originals/{archive_token}` (48 random chars, unique per frame,
  assigned in the model's `creating` hook). It used to be
  `/timelapse/frames/{id}?original=1` — a sequential id plus a flag, so one
  reachable frame meant the whole table could be walked by counting. Gate:
  `ProjectTimelapseFrame::archiveVisibleTo()` — the TAKER, or an Admin of
  the vendor that OWNS the project. Everyone else 404s (not 403 — a token
  in the wrong hands must not confirm it names a real file), and
  `lightboxFrames()` emits `original => null` for them so the "Show
  original" chip never renders. The frame route no longer serves the
  archive at all; a stale `?original=1` link quietly yields the blurred
  display copy. Response is `private, no-store` — the body is unblurred.
- **Build a timelapse from picked photos**: select mode's "Timelapse N"
  button → `createTimelapseFromSelection()`. Frames are COPIED (album stays
  a record), sorted by `shot_at` not tap order, sharing the immutable
  `original_path` (one archive file, many sequences — alignment still gets
  full resolution), frame #1 becomes the anchor, and the rest chain through
  `AlignTimelapseFrame(reframe: true)`.

**Prod deploy needs** in the aligner venv: `pillow-heif piexif color-matcher`
(pip). All degrade gracefully when missing.

## Interior sequences (learned on project 373, "Can Delivery")

A room shot from 10-15 feet behaves nothing like a house shot from 50: one
step changes apparent scale 15-25%, so the wobble caps refuse nearly every
frame (17 of 22 there, all wanting scale 0.65-0.97). The fix is NOT to
loosen the automatic caps — it is to re-anchor deliberately:

- **Pick the TIGHTEST frame as the anchor.** Then every other frame crops
  inward (zero invented border). Anchoring the widest frame forces everyone
  else to shrink, leaving 30-45% of the canvas to invent.
- Render from originals (reframe mode does this) so the crop stays sharp:
  measured 12.8 vs 22.1 detail (Laplacian variance) when upscaling the
  1920px copy instead.
- The trade to state out loud: cropping to the tightest common view loses
  the widest frames' extra field of view (a window, in that job).

## The hard-frame playbook (imported frame, different vantage)

Proven on frame 43 (April HEIC, farther/centered/level vs the sequence's
close/oblique/tilted viewpoint). Escalation order:

1. **Match the sequence's look, not absolute geometry.** The user's rule,
   learned the hard way in both directions: match the sequence's own house
   outline — skew/keystone IS wanted when the sequence is oblique; plumb
   walls only where the sequence has them plumb. And composition counts:
   the house must FILL the frame like the others ("needs to be cropped to
   match"), not just land on the right landmarks.
2. **Hand-seed landmark pairs.** Render both images with a coordinate grid
   (crops at 1:1), read 6–10 correspondences on distinctive SHARED features:
   window corners, door corners, gable apex, satellite dish, chimneys.
   Facade-plane features for rigid fits; include depth features only for
   full homographies.
3. **Least-squares homography** through the pairs onto the anchor/neighbor
   canvas (`cv2.findHomography(src, dst, 0)`). This is what shipped. RANSAC/
   LMEDS on clustered or banded points goes DEGENERATE — always sanity-check
   corner displacement and center-vertical lean before rendering.
4. **Constrained DLT for local corrections**: weighted synthetic pairs bolt
   constraints onto the fit — e.g. verticality of an edge the fit over-leans
   (top/bottom of the garage corner forced to share one X, weight ×5; fixed
   a −17.6° lean). But a homography cannot stretch one region alone — pulling
   one corner 50px dragged every landmark (residuals →150px). For that use:
5. **Monotone 1-D remaps on the finished frame**: x-only (or y-only) piecewise
   map, smoothed (Gaussian σ25 on the map keeps it monotone), e.g. corner
   155→105, door 470 pinned, identity rightward. Verticals/horizontals stay
   straight BY CONSTRUCTION; the untouched side is identity. Same family:
   separable 1-D projective x'=(ax+b)/(cx+1), y' likewise, absorbs a
   foreshortening gradient (measured 1.31→0.85 across one facade) with zero
   lean. These are the plumb-safe tools.
6. **Render ONCE from the full-res original** — compose every transform
   (homography ∘ k-scale, remaps inverted into the sampling grid) into a
   single warp of `original-*.jpg`. Never restack warps of warps.
7. **Fill big gaps from the crossfade NEIGHBOR** (not only the anchor): the
   filled band then literally doesn't change during the 1→2 transition.
   Grade + blur + wide-gradient per patch_gap; for big seasonal gaps go
   wider (dilate 61, σ45).
8. **Verify visually, never by landmark numbers alone.** NCC/template matches
   LIE on repetitive architecture (identical windows, siding courses) — they
   reported ±20px while the frame was 20% under-zoomed. Trust: 50/50 blends,
   stacked side-by-sides with gridlines, and UNIQUE objects (dish, chimney)
   for scale checks. The user's eye caught what the numbers missed, twice.
9. **Tone for backlit frames**: after harmonize, the house can still sit dark
   (CDF maps dark-to-dark regardless of object). Add a shadows lift: Gaussian
   L bump (amp ~15–25 total, center L≈75–85, σ≈40), tapered to zero below
   L 25 (black point) and irrelevant above ~150 (sky untouched). Measure a
   house-band L mean against neighbors (target within ~5).
10. **Sun flare**: estimate the veiling field as `min` over BOTH flare-free
    neighbors of the heavily-smoothed (σ45→σ25) positive luminance excess —
    real scene changes match one neighbor and drop out of the min — subtract
    ~0.9× from L only. The sharp flare core stays (removing it = inventing
    content). Scratchpad pattern from Aug 2026 session.
11. **Persist**: overwrite the `aligned-*` file, `touch()` the frame row,
    then re-run `HarmonizeTimelapseFrameColor(frameId, anchorId)` if the
    geometry changed (color rides on top). Everything is re-derivable from
    originals; keep a scratch backup of the outgoing file anyway.

## Failure modes to remember

- Optical flow + scene change = melted structure (wavy studs). Twice.
- Sky/region masks in color work = washed canopies. Banned.
- Raw MKL = hue rotation (salmon patio). Limit chroma, always.
- 3-channel histogram matching = mottled skies. L-only, slope-clamped.
- RANSAC on a y-banded landmark set = wild extrapolation (2900px corners).
- NCC on repetitive facades = confidently wrong offsets.
- ECC refinement after the caps check can breach the caps — recheck the
  composite (fixed in align_frame.py).
- Photometric stats over warp gaps = grade driven by black borders (fixed:
  stats masked to coverage).
- `save()` skips `updated_at` when the path string didn't change — always
  `touch()` after rewriting an aligned file in place, or the immutable URLs
  serve the stale image.
- Plain-mean overlap error + a person mid-frame = a flattened candidate race
  where the wrong motion-layer fit wins by the simplicity tie-break. Trim
  the worst 25% before averaging.
- The SAME trim hides a misregistered SUBJECT: on close-up frames whose
  wall plane and cabinet plane disagree (parallax from a stepped stance),
  the compromise fit doubles the cabinets and the trim discards exactly
  those pixels, so it wins anyway. When the subject region is what must
  line up, restrict feature matching to it (keypoints below the window
  band, y>400 canvas / y>650 original worked on project 373) and chain
  frame-to-frame — the featureless window absorbs the drift invisibly.
- A fill band is registered globally but judged locally at the seam: tape
  lines dead-ended at the joint until patch_gap phase-correlated the seam
  ring and shifted the fill (capped ±40px), and band paper stayed grayer
  than the paper it touched until the grade was measured on the seam ring
  instead of the whole frame.
- Comparing `error` values measured against DIFFERENT references = the
  visibly better fit loses (lighting gap ≫ geometry gap). Judge both passes
  against the anchor, post-photometric, covered pixels only (`ALIGN_JUDGE`).
- Filling gaps from the nearest aligned frame = compounding blur (fill of a
  fill of a fill) or someone's legs in six frames' borders. Fill from the
  anchor's canvas, always.
- ECC as a second warp AFTER cover-fit re-opens the gap it closed — compose
  ECC into the matrix, run cover-fit LAST, warp once.
- **SIFT memory scales with PIXELS (~215MB/megapixel).** A 24MP original
  (5712x4284) peaked at 5.3GB and the kernel SIGKILLed it — "signal 9" in
  the failed job, no PHP error, frame silently unaligned. `ALIGN_WORK_MAX_PX`
  (default 3400) caps the DETECTION copy only; keypoints are scaled back to
  full-res coordinates so every fit/warp/gap measurement is unchanged.
  Set it ABOVE the usual phone original: at 2400 a 3024px frame lost 10
  inliers and its rotation swung ~4 degrees. Verified: 3024px frames come
  out identical (work_scale 1.0), the 24MP one drops 5.3GB -> 2.2GB.
- **The OpenCV jobs must own a single-process queue.** Each alignment is a
  ~1.7GB python process; Horizon's default supervisor runs 10, which asked
  ~17GB of an 8GB box. They ride the `timelapse` supervisor (maxProcesses 1,
  600s, nice 10) — and every `Bus::chain()` must repeat `->onQueue('timelapse')`,
  because chaining OVERWRITES each job's own queue with the chain's.
