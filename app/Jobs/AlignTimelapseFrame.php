<?php

namespace App\Jobs;

use App\Models\ProjectTimelapseFrame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Register a freshly-shot timelapse frame onto its sequence's FIRST frame, so
 * handheld shots line up exactly (the onion skin gets close; this removes the
 * residual wobble). Runs scripts/align_frame.py — ORB keypoints + RANSAC
 * homography via OpenCV — the same shell-out pattern as ffmpeg.
 *
 * Anchoring every frame to frame #1 (rather than to the previous frame)
 * keeps error from accumulating across months of shots.
 *
 * Non-destructive: the original stays untouched; the warped copy lands in
 * aligned_path and viewers prefer it. A frame the aligner isn't confident
 * about (the scene changed too much — mid-renovation, it will) keeps only its
 * original rather than warping wrongly.
 */
class AlignTimelapseFrame implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /** Runs on the 600s 'timelapse' supervisor, so a big HEIC re-frame has
     *  room instead of being SIGKILLed halfway through. */
    public int $timeout = 300;


    /**
     * @param  bool  $reframe  A deliberate RE-ANCHOR, not a routine capture:
     *   the sequence is being rebuilt around a frame whose framing differs on
     *   purpose, so the minor-adjustment caps are lifted, the warp is sourced
     *   from the full-resolution ORIGINAL (a frame shot farther away has to
     *   zoom IN, and upscaling the 1920px sequence copy would show), and more
     *   gap is tolerated because a frame shot closer than the anchor cannot
     *   cover its canvas — the missing band is filled from the neighbour that
     *   already lives on it.
     */
    public function __construct(public int $frameId, public bool $reframe = false)
    {
        // OpenCV work peaks ~1.7GB per process; the 'timelapse' supervisor runs
        // exactly one at a time so concurrent frames can't OOM the box.
        $this->onQueue('timelapse');
    }

    public function handle(): void
    {
        $frame = ProjectTimelapseFrame::find($this->frameId);

        if (! $frame || $frame->aligned_path) {
            return;
        }

        $anchor = $frame->timelapse?->anchorFrame();

        if (! $anchor) {
            return;
        }

        // The anchor gets no geometry (it IS the reference) but still gets
        // its colors eased to the sequence target — its own quirks (a light
        // flare, say) must not stay the odd frame out.
        $photoOnly = $anchor->id === $frame->id;

        $python = (string) config('services.timelapse_align.python');

        if (! is_executable($python)) {
            Log::channel('timelapse')->warning('Align skipped — python not executable', ['python' => $python]);

            return;
        }

        $disk = Storage::disk($frame->disk);
        // display_path, not path: when the anchor was COMPOSED in the studio
        // (reset to its original, then levelled or cropped) that composition
        // IS the canvas, and matching against its raw copy would register the
        // sequence onto a framing the anchor no longer shows.
        $referencePath = $disk->path($photoOnly ? $frame->path : $anchor->display_path);
        // Re-anchoring can demand a real zoom; sample the archive original so
        // it costs no sharpness. Routine captures stay on the sequence copy.
        $targetPath = $disk->path(
            $this->reframe && ! $photoOnly && $frame->original_path && $disk->exists($frame->original_path)
                ? $frame->original_path
                : $frame->path
        );

        if (! is_file($referencePath) || ! is_file($targetPath)) {
            return;
        }

        $alignedRelative = preg_replace('/([^\/]+)$/', 'aligned-$1', $frame->path);
        $alignedAbsolute = $disk->path($alignedRelative);

        // Warp gaps get patched from the nearest PRECEDING frame whose canvas
        // is entirely real photo, else the anchor's canvas. Both rules are
        // scar tissue: a fill may show the PAST but never the FUTURE (an
        // anchor fill once placed a cabinet into a frame shot before that
        // cabinet existed), and a patched frame must never be a source
        // (fills compound — six hops of re-grading and re-feathering turned
        // a border ring to mush; "nearest crisp in either direction" once
        // pasted a later frame's delivery crew member into six borders).
        // patch_gap grades the fill toward each frame's own palette.
        $neighbor = $photoOnly ? null : self::nearestAlignedFrameFor($frame, $anchor->sort_order);
        $crisp = $photoOnly ? null : self::precedingCrispFillFor($frame);
        $fillPath = $photoOnly ? null : $disk->path($crisp?->aligned_path ?? $anchor->display_path);

        $process = new Process([
            $python,
            base_path('scripts/align_frame.py'),
            $referencePath,
            $targetPath,
            $alignedAbsolute,
            (string) config('services.timelapse_align.min_inliers', 25),
        ], null, array_filter([
            'ALIGN_GEOMETRY' => $photoOnly ? '0' : '1',
            // Report the fit in the manual aligner's terms (see below): the
            // width of the copy a human would align against.
            'ALIGN_PREVIEW_WIDTH' => (string) (@getimagesize($disk->path($frame->path))[0] ?? 0),
            // The sequence's median LAB profile: every frame eases toward the
            // collective look, not toward frame #1's own quirks.
            'ALIGN_TARGET' => self::sequenceTargetFor($frame),
            // Re-anchoring on a WIDER frame means every closer shot has to
            // shrink onto a canvas it never covered; that band is real
            // neighbour pixels, seconds apart in a static room, so the budget
            // is generous here (0.20, and 0.50 once a fill source exists) —
            // but still finite: past half the canvas the result is more
            // neighbour than frame, and the frame stays honestly as shot.
            'ALIGN_MAX_BORDER' => (string) ($this->reframe ? 0.20 : config('services.timelapse_align.max_border', 0.08)),
            'ALIGN_FILL' => $fillPath && is_file($fillPath) ? $fillPath : null,
            // What playback actually shows is each frame against the frame
            // BESIDE it, so both passes are judged on the transition: error
            // against the nearest aligned neighbour, post-photometric, on
            // covered pixels. (Judging against the anchor once kept an
            // anchor fit whose 2% scale error, invisible absolutely, made
            // three near-identical frames pulse against each other.)
            'ALIGN_JUDGE' => $neighbor ? $disk->path($neighbor->aligned_path) : null,
            // Strict filter — bare array_filter eats the '0' string and would
            // silently turn photo-only back into full geometry.
        ], fn ($v) => $v !== null) + $this->reframeEnv());
        $process->setTimeout(240);
        $process->run();

        $diagnostics = json_decode(trim($process->getOutput()), true) ?: [];
        $aligned = $process->getExitCode() === 0;

        // ALWAYS race a second fit referenced on the nearest aligned
        // neighbour. The anchor pass keeps the sequence globally pinned, but
        // the neighbour shares this frame's content (the anchor may be hours
        // and a room's worth of change away — or, for the sequence's first
        // frames, unrecognizable), and same-session frames register on it
        // near-perfectly. The transition judge above decides which ships.
        if (! $photoOnly && $neighbor !== null) {
            $retryAbsolute = $alignedAbsolute.'.retry.jpg';

            $retry = new Process([
                $python,
                base_path('scripts/align_frame.py'),
                $disk->path($neighbor->aligned_path),
                $targetPath,
                $retryAbsolute,
                (string) config('services.timelapse_align.min_inliers', 25),
            ], null, [
                'ALIGN_TARGET' => self::sequenceTargetFor($frame) ?? '',
                'ALIGN_PREVIEW_WIDTH' => (string) (@getimagesize($disk->path($frame->path))[0] ?? 0),
                'ALIGN_MAX_BORDER' => (string) ($this->reframe ? 0.20 : config('services.timelapse_align.max_border', 0.08)),
                // Same backward-in-time fill as the first pass.
                'ALIGN_FILL' => $fillPath,
                // Same transition judge as the first pass (here it doubles
                // as the reference).
                'ALIGN_JUDGE' => $disk->path($neighbor->aligned_path),
            ] + $this->reframeEnv());
            $retry->setTimeout(240);
            $retry->run();

            $retryDiag = json_decode(trim($retry->getOutput()), true) ?: [];

            // Nothing to beat when the first pass produced no frame at all —
            // any confident neighbour fit beats staying unaligned.
            $baseline = $aligned
                ? ($diagnostics['judge_error'] ?? $diagnostics['error'] ?? PHP_FLOAT_MAX)
                : PHP_FLOAT_MAX;

            if ($retry->getExitCode() === 0
                && is_file($retryAbsolute)
                && ($retryDiag['judge_error'] ?? $retryDiag['error'] ?? PHP_FLOAT_MAX) < $baseline) {
                rename($retryAbsolute, $alignedAbsolute);
                $diagnostics = $retryDiag + ['reference' => 'neighbour frame #'.$neighbor->id];
                $aligned = true;
            } else {
                @unlink($retryAbsolute);
            }
        }

        if ($aligned && is_file($alignedAbsolute)) {
            // Privacy: reframe mode samples the unblurred archive original,
            // so the freshly-rendered display copy gets faces re-blurred
            // before anything serves it. No-op when no faces.
            \App\Services\FaceBlur::blur($alignedAbsolute);

            // Persist the fit in the manual aligner's parameterisation so the
            // modal can open on exactly what the sequence plays — a frame the
            // pipeline zoomed in has overflow to turn into, one opened at 1:1
            // has none.
            $frame->forceFill([
                'aligned_path' => $alignedRelative,
                // fabricated rides along so nearestCrispFillFor() can tell a
                // fully-real canvas from one that was itself patched.
                'align_transform' => isset($diagnostics['preview_transform'])
                    ? $diagnostics['preview_transform'] + ['fabricated' => (float) ($diagnostics['fabricated'] ?? 0)]
                    : null,
            ])->save();

            Log::channel('timelapse')->info('Frame aligned', ['frame_id' => $frame->id] + $diagnostics);

            $this->chainColorGrade($frame, $anchor);

            return;
        }

        @unlink($alignedAbsolute);

        // Exit 2 = a considered "not confident enough" — expected sometimes,
        // never an error. Anything else is.
        if ($process->getExitCode() === 2) {
            Log::channel('timelapse')->info('Frame left unaligned (low confidence)', [
                'frame_id' => $frame->id,
            ] + $diagnostics);

            // Geometry stays as shot, but the COLORS still join the sequence.
            $this->chainColorGrade($frame, $anchor);

            return;
        }

        Log::channel('timelapse')->error('Align failed', [
            'frame_id' => $frame->id,
            'exit' => $process->getExitCode(),
            'stderr' => mb_substr($process->getErrorOutput(), 0, 1500),
        ]);
    }

    /**
     * Caps for a deliberate re-anchor. Empty for routine captures, which keep
     * the strict wobble-only limits.
     *
     * The offset cap goes wide because warping a full-resolution original
     * onto the sequence canvas measures "centre offset" between two different
     * pixel grids — the number stops meaning what it means for same-size
     * frames. What still guards honesty is the border limit above and the
     * measured overlap error.
     *
     * @return array<string, string>
     */
    protected function reframeEnv(): array
    {
        if (! $this->reframe) {
            return [];
        }

        return [
            'ALIGN_MAX_SCALE_DELTA' => '0.60',
            'ALIGN_MAX_ROTATION' => '5',
            'ALIGN_MAX_OFFSET' => '0.45',
            'ALIGN_MIN_SCALE' => '0.45',
            'ALIGN_MAX_SCALE' => '1.60',
            // A stance change between shots is a real perspective change;
            // matching the previous frame through it needs projective room.
            'ALIGN_MAX_KEYSTONE' => '60',
        ];
    }

    /**
     * Every processed frame is automatically color-graded toward the anchor
     * once its geometry settles — no manual harmonize step. The grade job
     * self-skips when the frame IS the anchor.
     */
    protected function chainColorGrade(ProjectTimelapseFrame $frame, ProjectTimelapseFrame $anchor): void
    {
        if ($frame->timelapse?->kind !== \App\Models\ProjectTimelapse::KIND_TIMELAPSE || $anchor->id === $frame->id) {
            return;
        }

        HarmonizeTimelapseFrameColor::dispatch($frame->id, $anchor->id);
    }

    /**
     * Median LAB profile across the sequence (cached briefly): the shared
     * color target that makes frames navigate without pulsing.
     */
    public static function sequenceTargetFor(ProjectTimelapseFrame $frame): ?string
    {
        return Cache::remember(
            "timelapse_target:{$frame->project_timelapse_id}",
            now()->addMinutes(10),
            function () use ($frame) {
                $python = (string) config('services.timelapse_align.python');

                $paths = ProjectTimelapseFrame::query()
                    ->where('project_timelapse_id', $frame->project_timelapse_id)
                    ->orderByDesc('id')
                    ->limit(12)
                    ->get()
                    ->map(fn ($f) => Storage::disk($f->disk)->path($f->path))
                    ->filter(fn ($p) => is_file($p))
                    ->values()
                    ->all();

                if (count($paths) < 2 || ! is_executable($python)) {
                    return null;
                }

                $process = new Process([
                    $python, base_path('scripts/align_frame.py'), '--stats', ...$paths,
                ]);
                $process->setTimeout(30);
                $process->run();

                $out = json_decode(trim($process->getOutput()), true) ?: [];

                return ($out['ok'] ?? false) ? (string) $out['target'] : null;
            },
        );
    }

    /**
     * The nearest frame in EITHER direction that already has an aligned copy,
     * ties broken toward the PREVIOUS frame — playback flows forward, so the
     * transition a viewer scrutinises is previous→current ("it should be
     * matched/aligned with the previous frame"). A tie once went to the
     * anchor side instead and registered the person-occluded frame against
     * the wrong neighbour. Either direction still beats "older only", which
     * left the sequence's first frame with no fallback reference at all.
     */
    public static function nearestAlignedFrameFor(ProjectTimelapseFrame $frame, int $anchorSort): ?ProjectTimelapseFrame
    {
        return self::alignedSiblingsOf($frame)
            ->sortBy(fn ($f) => abs($f->sort_order - $frame->sort_order) * 2
                + ($f->sort_order < $frame->sort_order ? 0 : 1))
            ->first();
    }

    /**
     * The nearest EARLIER aligned frame whose canvas is entirely real photo
     * (fabricated ≈ 0 in its stored fit) — the only fill source that can
     * never show the future and never pass along someone else's patch.
     * Null is fine; the caller falls back to the anchor's canvas.
     */
    public static function precedingCrispFillFor(ProjectTimelapseFrame $frame): ?ProjectTimelapseFrame
    {
        return self::alignedSiblingsOf($frame)
            ->filter(fn ($f) => $f->sort_order < $frame->sort_order
                && (float) ($f->align_transform['fabricated'] ?? 1.0) < 0.01)
            ->sortByDesc('sort_order')
            ->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ProjectTimelapseFrame> */
    protected static function alignedSiblingsOf(ProjectTimelapseFrame $frame)
    {
        return ProjectTimelapseFrame::query()
            ->where('project_timelapse_id', $frame->project_timelapse_id)
            ->whereNotNull('aligned_path')
            ->whereKeyNot($frame->id)
            ->get();
    }
}
