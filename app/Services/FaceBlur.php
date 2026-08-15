<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Blurs faces on DISPLAY copies of project images — the privacy layer for
 * jobsite photos. Runs scripts/blur_faces.py (YuNet detector, elliptical
 * feathered blur) against every path given, in place.
 *
 * The `original-*` archive copies are NEVER passed through here: they are
 * the evidentiary record, faces and all, with their EXIF intact. Anything
 * a viewer is served — sequence copies, aligned copies, composed anchors —
 * goes through this right after it is written. Files without faces are
 * left byte-identical.
 *
 * Degrades to a no-op (with a log line) when the CV python is missing, the
 * same grace every other pipeline stage extends.
 */
class FaceBlur
{
    /**
     * @param  string  ...$absolutePaths  display-copy files to blur in place
     * @return array<string, int|null> basename => faces blurred (null = unreadable)
     */
    public static function blur(string ...$absolutePaths): array
    {
        $paths = array_values(array_filter($absolutePaths, 'is_file'));

        if ($paths === []) {
            return [];
        }

        $python = (string) config('services.timelapse_align.python');

        if (! is_executable($python)) {
            Log::channel('timelapse')->warning('Face blur skipped — python not executable', ['python' => $python]);

            return [];
        }

        $process = new Process([$python, base_path('scripts/blur_faces.py'), ...$paths]);
        $process->setTimeout(30 + 5 * count($paths));
        $process->run();

        $out = json_decode(trim($process->getOutput()), true) ?: [];

        if (! ($out['ok'] ?? false)) {
            Log::channel('timelapse')->warning('Face blur failed', [
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
            ]);

            return [];
        }

        $faces = $out['faces'] ?? [];

        if (array_sum(array_filter($faces, 'is_int')) > 0) {
            Log::channel('timelapse')->info('Faces blurred', ['faces' => $faces]);
        }

        return $faces;
    }
}
