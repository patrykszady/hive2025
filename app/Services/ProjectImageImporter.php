<?php

namespace App\Services;

use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

/**
 * The one way an image file becomes a project frame — used by the studio's
 * camera/upload paths and by "Add to Project" on a text message. Every frame
 * gets the same treatment: untouched archive copy, capped sequence copy, and
 * whatever EXIF time/GPS the source still carries.
 */
class ProjectImageImporter
{
    /** The longest edge every stored sequence copy is capped to. */
    public const MAX_EDGE = 1920;

    /**
     * @param  array{lat: ?float, lng: ?float, accuracy: ?int}|null  $gps  overrides EXIF (the camera's live fix)
     */
    public function storeImage(
        ProjectTimelapse $timelapse,
        string $sourcePath,
        ?string $originalName = null,
        ?Carbon $shotAtFallback = null,
        ?array $gps = null,
        ?Carbon $shotAtOverride = null,
        ?int $takenByUserId = null,
        ?string $takenByName = null,
    ): ProjectTimelapseFrame {
        // Read time and location BEFORE touching the pixels — orientate/
        // encode strips EXIF, so this is the only moment either exists.
        $shotAt = $shotAtOverride ?? $this->exifShotAt($sourcePath, $shotAtFallback);
        $gps = ($gps['lat'] ?? null) !== null ? $gps : $this->exifGps($sourcePath);

        $filename = Str::uuid().'.jpg';
        $directory = sprintf('timelapse/%d', $timelapse->project_id);

        // 1. THE ARCHIVE COPY — byte-for-byte, written first, never touched.
        $originalPath = sprintf('%s/original-%s', $directory, $filename);
        Storage::disk('files')->put($originalPath, file_get_contents($sourcePath));

        // 2. THE SEQUENCE COPY — oriented and capped for playback/alignment.
        $image = Image::make($sourcePath)->orientate();

        if (max($image->width(), $image->height()) > self::MAX_EDGE) {
            $image->resize(self::MAX_EDGE, self::MAX_EDGE, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        $path = sprintf('%s/%s', $directory, $filename);
        Storage::disk('files')->put($path, (string) $image->encode('jpg', 88));

        $frame = ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id,
            'taken_by_user_id' => $takenByUserId,
            'taken_by_name' => $takenByName,
            'filename' => $filename,
            'original_filename' => $originalName,
            'path' => $path,
            'original_path' => $originalPath,
            'disk' => 'files',
            'shot_at' => $shotAt,
            'latitude' => $gps['lat'] ?? null,
            'longitude' => $gps['lng'] ?? null,
            'location_accuracy' => $gps['accuracy'] ?? null,
            'sort_order' => ((int) $timelapse->frames()->max('sort_order')) + 1,
        ]);

        if ($timelapse->isTimelapse()) {
            \App\Jobs\AlignTimelapseFrame::dispatch($frame->id)->afterCommit();
        }

        return $frame;
    }

    /**
     * Absolute path of a stored SMS media entry, whatever shape it was saved
     * in ("/storage/sms-media/…", bare "sms-media/…", "sms-attachments/…").
     * Null for external URLs and files that aren't on this machine.
     */
    public function resolveSmsMediaPath(string $url): ?string
    {
        if (str_starts_with($url, 'http')) {
            return null;
        }

        $relative = ltrim(str_replace('/storage/', '', $url), '/');

        if (! str_starts_with($relative, 'sms-media/') && ! str_starts_with($relative, 'sms-attachments/')) {
            $relative = 'sms-media/'.$relative;
        }

        foreach ([storage_path('files/'.$relative), storage_path('app/public/'.$relative)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * EXIF DateTimeOriginal, else the given fallback (a message's sent time),
     * else the file's mtime, else now. Bad EXIF is normal — never fatal.
     */
    public function exifShotAt(string $path, ?Carbon $fallback = null): Carbon
    {
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($path);
                $taken = $exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null;

                if (is_string($taken) && $taken !== '' && ! str_starts_with($taken, '0000')) {
                    return Carbon::createFromFormat('Y:m:d H:i:s', $taken);
                }
            } catch (\Throwable) {
                // Unreadable EXIF is not a reason to lose the photo.
            }
        }

        if ($fallback) {
            return $fallback;
        }

        $mtime = @filemtime($path);

        return $mtime ? Carbon::createFromTimestamp($mtime) : now();
    }

    /**
     * EXIF GPS as decimal degrees, or null — photos that passed through a
     * messaging app or carrier have had it stripped, and that's normal.
     *
     * @return array{lat: float, lng: float, accuracy: null}|null
     */
    public function exifGps(string $path): ?array
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($path);

            if (! isset($exif['GPSLatitude'], $exif['GPSLongitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitudeRef'])) {
                return null;
            }

            $lat = self::gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
            $lng = self::gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']);

            if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat === 0.0 && $lng === 0.0)) {
                return null;
            }

            return ['lat' => $lat, 'lng' => $lng, 'accuracy' => null];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * EXIF stores coordinates as degree/minute/second rationals ("41/1",
     * "52/1", "3456/100") with a hemisphere letter.
     */
    public static function gpsToDecimal(array $dms, string $ref): ?float
    {
        $parts = array_map(function ($value) {
            if (is_numeric($value)) {
                return (float) $value;
            }

            [$num, $den] = array_pad(explode('/', (string) $value, 2), 2, 1);

            return (float) $den == 0.0 ? null : (float) $num / (float) $den;
        }, array_pad(array_values($dms), 3, 0));

        if (in_array(null, $parts, true)) {
            return null;
        }

        $decimal = $parts[0] + $parts[1] / 60 + $parts[2] / 3600;

        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$decimal : $decimal;
    }
}
