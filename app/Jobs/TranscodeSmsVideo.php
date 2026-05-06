<?php

namespace App\Jobs;

use App\Events\SmsMessageReceived;
use App\Models\SmsMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Transcode an inbound SMS media file to a browser-friendly H.264 MP4.
 *
 * MMS carriers compress incoming videos to 3GPP/H.263 which Edge/Chrome/Firefox
 * cannot play natively. This job runs ffmpeg to produce a sibling .mp4 (H.264/AAC,
 * faststart) and rewrites the message's media_urls to point at the new file.
 * The original is preserved.
 */
class TranscodeSmsVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * Source extensions that need transcoding for browser playback.
     */
    protected const TRANSCODE_EXTS = ['3gp', '3gpp', 'mov', 'avi', 'mkv', 'qt', 'amr', 'mp4'];

    public function __construct(public int $messageId, public string $relativePath, public bool $force = false) {}

    public function handle(): void
    {
        $message = SmsMessage::find($this->messageId);

        if (! $message) {
            return;
        }

        $ext = strtolower(pathinfo($this->relativePath, PATHINFO_EXTENSION));

        if (! in_array($ext, self::TRANSCODE_EXTS, true)) {
            return;
        }

        $disk = Storage::disk('files');

        if (! $disk->exists($this->relativePath)) {
            Log::channel('telnyx')->warning('TranscodeSmsVideo: source missing', [
                'message_id' => $this->messageId,
                'path' => $this->relativePath,
            ]);

            return;
        }

        $sourceFull = $disk->path($this->relativePath);
        $targetRel = preg_replace('/\.[^.]+$/', '.mp4', $this->relativePath) ?? $this->relativePath;
        $inPlace = $targetRel === $this->relativePath;

        if ($inPlace && ! $this->force) {
            return;
        }

        // For in-place re-transcode, prefer an original (3gp/mov/etc) sibling so we
        // don't re-encode a previously-transcoded mp4 (which may have lost crop bars
        // info or accumulated quality loss).
        if ($inPlace) {
            foreach (['3gp', '3gpp', 'mov', 'avi', 'mkv', 'qt', 'amr'] as $origExt) {
                $sibling = preg_replace('/\.[^.]+$/', '.' . $origExt, $this->relativePath);
                if ($sibling && $disk->exists($sibling)) {
                    $sourceFull = $disk->path($sibling);
                    break;
                }
            }
        }

        $finalTargetFull = $disk->path($targetRel);
        $targetFull = $inPlace
            ? $disk->path(preg_replace('/\.mp4$/', '.tmp.mp4', $targetRel) ?? ($targetRel . '.tmp'))
            : $finalTargetFull;

        if ($this->force && ! $inPlace && file_exists($finalTargetFull)) {
            @unlink($finalTargetFull);
        }

        if (! $inPlace && file_exists($finalTargetFull)) {
            return;
        }

        $ffmpeg = trim((string) shell_exec('which ffmpeg')) ?: '/usr/bin/ffmpeg';

        if (! is_executable($ffmpeg)) {
            Log::channel('telnyx')->error('TranscodeSmsVideo: ffmpeg not available', [
                'ffmpeg' => $ffmpeg,
            ]);

            return;
        }

        if (file_exists($targetFull)) {
            @unlink($targetFull);
        }

        $cropFilter = $this->detectCropFilter($ffmpeg, $sourceFull);
        $videoFilters = array_values(array_filter([
            $cropFilter,
            'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            'setsar=1',
        ]));

        $process = new Process([
            $ffmpeg,
            '-y',
            '-i', $sourceFull,
            '-vf', implode(',', $videoFilters),
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '23',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $targetFull,
        ]);
        $process->setTimeout(180);

        try {
            $process->mustRun();
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('TranscodeSmsVideo: ffmpeg failed', [
                'message_id' => $this->messageId,
                'path' => $this->relativePath,
                'error' => $e->getMessage(),
            ]);

            if (file_exists($targetFull)) {
                @unlink($targetFull);
            }

            return;
        }

        if ($inPlace) {
            if (file_exists($finalTargetFull)) {
                @unlink($finalTargetFull);
            }

            try {
                @rename($targetFull, $finalTargetFull);
            } catch (\Throwable $e) {
                Log::channel('telnyx')->error('TranscodeSmsVideo: failed to finalize in-place mp4 transcode', [
                    'message_id' => $this->messageId,
                    'path' => $this->relativePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Rewrite media_urls: replace the source path with the .mp4 sibling.
        $urls = (array) $message->media_urls;
        $changed = false;
        foreach ($urls as $i => $u) {
            $candidate = ltrim((string) $u, '/');
            $candidate = preg_replace('#^storage/#', '', $candidate);

            if ($candidate === $this->relativePath) {
                $urls[$i] = $targetRel;
                $changed = true;
            }
        }

        if ($changed) {
            $message->update(['media_urls' => array_values($urls)]);

            if ($message->thread_id) {
                try {
                    SmsMessageReceived::dispatch($message->thread_id);
                } catch (\Throwable $e) {
                    // ignore broadcast errors
                }
            }
        }

        Log::channel('telnyx')->info('TranscodeSmsVideo: success', [
            'message_id' => $this->messageId,
            'source' => $this->relativePath,
            'target' => $targetRel,
            'crop' => $cropFilter,
            'src_size' => @filesize($sourceFull) ?: 0,
            'dst_size' => @filesize($finalTargetFull) ?: 0,
        ]);
    }

    protected function detectCropFilter(string $ffmpeg, string $sourceFull): ?string
    {
        // Sample multiple points so we don't bias toward a single dark frame.
        // Round=2 gives per-2-pixel precision (vs 16 leaving residual bars).
        // Limit=32 catches dark-gray bars (not pure black) common in carrier-letterboxed MMS.
        $offsets = ['0', '2', '5', '10'];
        $combined = '';

        foreach ($offsets as $ss) {
            $process = new Process([
                $ffmpeg,
                '-ss', $ss,
                '-i', $sourceFull,
                '-t', '4',
                '-vf', 'cropdetect=limit=32:round=2:reset=1',
                '-an',
                '-f', 'null',
                '-',
            ]);
            $process->setTimeout(45);
            $process->run();
            $combined .= "\n" . $process->getErrorOutput() . "\n" . $process->getOutput();
        }

        return self::extractCropFromOutput($combined);
    }

    public static function extractCropFromOutput(string $output): ?string
    {
        if (! preg_match_all('/crop=(\d+):(\d+):(\d+):(\d+)/', $output, $matches, PREG_SET_ORDER) || empty($matches)) {
            return null;
        }

        // Pick the smallest-area crop (most aggressive) so baked-in letterbox bars
        // are stripped rather than averaged in.
        $best = null;
        $bestArea = PHP_INT_MAX;

        foreach ($matches as $m) {
            $w = (int) $m[1];
            $h = (int) $m[2];
            $x = (int) $m[3];
            $y = (int) $m[4];

            if ($w < 32 || $h < 32 || $x < 0 || $y < 0) {
                continue;
            }

            $area = $w * $h;

            if ($area < $bestArea) {
                $bestArea = $area;
                $best = "{$w}:{$h}:{$x}:{$y}";
            }
        }

        return $best ? 'crop=' . $best : null;
    }
}
