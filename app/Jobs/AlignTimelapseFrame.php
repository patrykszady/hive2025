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

    /** Horizon runs 60s workers — stay inside that, don't get SIGKILLed. */
    public int $timeout = 55;

    /** Above this measured overlap error, try the previous frame as reference. */
    public const RETRY_ERROR = 15.0;

    public function __construct(public int $frameId)
    {
    }

    public function handle(): void
    {
        $frame = ProjectTimelapseFrame::find($this->frameId);

        if (! $frame || $frame->aligned_path) {
            return;
        }

        $anchor = ProjectTimelapseFrame::query()
            ->where('project_timelapse_id', $frame->project_timelapse_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $anchor) {
            return;
        }

        // The anchor gets no geometry (it IS the reference) but still gets
        // its colors eased to the sequence target — its own quirks (a light
        // flare, say) must not stay the odd frame out.
        $photoOnly = $anchor->id === $frame->id;

        $python = (string) config('services.timelapse_align.python');

        if (! is_executable($python)) {
            Log::warning('Timelapse align skipped — python not executable', ['python' => $python]);

            return;
        }

        $disk = Storage::disk($frame->disk);
        $referencePath = $disk->path($photoOnly ? $frame->path : $anchor->path);
        $targetPath = $disk->path($frame->path);

        if (! is_file($referencePath) || ! is_file($targetPath)) {
            return;
        }

        $alignedRelative = preg_replace('/([^\/]+)$/', 'aligned-$1', $frame->path);
        $alignedAbsolute = $disk->path($alignedRelative);

        $process = new Process([
            $python,
            base_path('scripts/align_frame.py'),
            $referencePath,
            $targetPath,
            $alignedAbsolute,
            (string) config('services.timelapse_align.min_inliers', 25),
        ], null, array_filter([
            'ALIGN_GEOMETRY' => $photoOnly ? '0' : '1',
            // The sequence's median LAB profile: every frame eases toward the
            // collective look, not toward frame #1's own quirks.
            'ALIGN_TARGET' => $this->sequenceTarget($frame),
            'ALIGN_MAX_BORDER' => (string) config('services.timelapse_align.max_border', 0.08),
            // Strict filter — bare array_filter eats the '0' string and would
            // silently turn photo-only back into full geometry.
        ], fn ($v) => $v !== null));
        $process->setTimeout(50);
        $process->run();

        $diagnostics = json_decode(trim($process->getOutput()), true) ?: [];
        $aligned = $process->getExitCode() === 0;

        // A poor fit against the distant anchor often just means the crew's
        // stance drifted over time — the PREVIOUS aligned frame shares the
        // newer viewpoint AND already lives in anchor space, so retry against
        // it and keep whichever result measures tighter.
        $looseFit = $aligned && ($diagnostics['error'] ?? 0) > self::RETRY_ERROR;

        // Same story with a worse ending: the stance drifted so far that the
        // warp onto anchor space left more canvas empty than the aligner will
        // invent. The nearer viewpoint is exactly what that needs, so this is
        // worth a retry before giving up on aligning the frame at all.
        $tooFar = $process->getExitCode() === 2
            && ($diagnostics['reason'] ?? '') === 'border too large';

        if (! $photoOnly
            && ($looseFit || $tooFar)
            && ($neighbor = $this->previousAlignedFrame($frame)) !== null) {
            $retryAbsolute = $alignedAbsolute.'.retry.jpg';

            $retry = new Process([
                $python,
                base_path('scripts/align_frame.py'),
                $disk->path($neighbor->aligned_path),
                $targetPath,
                $retryAbsolute,
                (string) config('services.timelapse_align.min_inliers', 25),
            ], null, [
                'ALIGN_TARGET' => $this->sequenceTarget($frame) ?? '',
                'ALIGN_MAX_BORDER' => (string) config('services.timelapse_align.max_border', 0.08),
            ]);
            $retry->setTimeout(50);
            $retry->run();

            $retryDiag = json_decode(trim($retry->getOutput()), true) ?: [];

            // Nothing to beat when the first pass produced no frame at all —
            // any confident retry is an improvement on staying unaligned.
            $baseline = $looseFit ? ($diagnostics['error'] ?? PHP_FLOAT_MAX) : PHP_FLOAT_MAX;

            if ($retry->getExitCode() === 0
                && is_file($retryAbsolute)
                && ($retryDiag['error'] ?? PHP_FLOAT_MAX) < $baseline) {
                rename($retryAbsolute, $alignedAbsolute);
                $diagnostics = $retryDiag + ['reference' => 'previous frame #'.$neighbor->id];
                $aligned = true;
            } else {
                @unlink($retryAbsolute);
            }
        }

        if ($aligned && is_file($alignedAbsolute)) {
            $frame->forceFill(['aligned_path' => $alignedRelative])->save();

            Log::info('Timelapse frame aligned', ['frame_id' => $frame->id] + $diagnostics);

            return;
        }

        @unlink($alignedAbsolute);

        // Exit 2 = a considered "not confident enough" — expected sometimes,
        // never an error. Anything else is.
        if ($process->getExitCode() === 2) {
            Log::info('Timelapse frame left unaligned (low confidence)', [
                'frame_id' => $frame->id,
            ] + $diagnostics);

            return;
        }

        Log::error('Timelapse align failed', [
            'frame_id' => $frame->id,
            'exit' => $process->getExitCode(),
            'stderr' => mb_substr($process->getErrorOutput(), 0, 1500),
        ]);
    }

    /**
     * Median LAB profile across the sequence (cached briefly): the shared
     * color target that makes frames navigate without pulsing.
     */
    protected function sequenceTarget(ProjectTimelapseFrame $frame): ?string
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

    /** The nearest OLDER frame that already has an aligned copy. */
    protected function previousAlignedFrame(ProjectTimelapseFrame $frame): ?ProjectTimelapseFrame
    {
        return ProjectTimelapseFrame::query()
            ->where('project_timelapse_id', $frame->project_timelapse_id)
            ->whereNotNull('aligned_path')
            ->where('sort_order', '<', $frame->sort_order)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();
    }
}
