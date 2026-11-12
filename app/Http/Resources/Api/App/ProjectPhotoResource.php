<?php

namespace App\Http\Resources\Api\App;

use App\Models\ProjectTimelapseFrame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A timelapse frame for the Hive Contractors app's photo gallery — the same
 * session-less signed URL pattern as ProjectResource::latestPhotos(), so
 * the gallery and the project detail's "latest photos" strip show
 * interoperable links.
 *
 * Three sizes of the same frame: `thumb_url` (480px, grids), `screen_url`
 * (1280px, what a phone plays a sequence at) and `url` (the 1920px frame,
 * for zooming in). `version` changes whenever the pixels behind them do, so
 * the app can keep a copy on the phone for as long as it stays the same.
 * `aligned` says the copy shown is registered onto the sequence's anchor —
 * its framing is the one the next shot should match.
 *
 * @mixin \App\Models\ProjectTimelapseFrame
 */
class ProjectPhotoResource extends JsonResource
{
    private bool $includesCaptureHints = false;

    /**
     * Also say how the frame was shot (lens, tilt) — for the frame the app's
     * camera lines the next shot up against. Reads the archive original's
     * EXIF, so it is asked for one frame at a time, never a whole grid.
     */
    public function withCaptureHints(): static
    {
        $this->includesCaptureHints = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectTimelapseFrame $frame */
        $frame = $this->resource;
        [$width, $height] = $this->displayDimensions($frame);

        $data = [
            'id' => $frame->id,
            'url' => self::signedFrameUrl($frame),
            'thumb_url' => self::signedFrameUrl($frame, 'thumb'),
            'screen_url' => self::signedFrameUrl($frame, 'screen'),
            'version' => $frame->version,
            'width' => $width,
            'height' => $height,
            'aligned' => (bool) $frame->aligned_path,
            'taken_at' => $frame->shot_at?->toIso8601String(),
            'taken_by' => $frame->taker_name,
            'collection_id' => $frame->project_timelapse_id,
            'sort_order' => $frame->sort_order,
        ];

        if ($this->includesCaptureHints) {
            $data['capture'] = $this->captureHints($frame);
        }

        return $data;
    }

    /**
     * A signed link to one size of a frame: null for the 1920px frame,
     * 'thumb' for the 480px grid copy, 'screen' for the 1280px phone copy.
     *
     * The link is the same for every request made on the same day (see
     * linkExpiry()) and carries the frame's version, so a phone or a proxy
     * that already holds the image is asked for the very same address again
     * and answers from its cache — and a re-aligned frame gets a new one.
     */
    public static function signedFrameUrl(ProjectTimelapseFrame $frame, ?string $size = null): string
    {
        return URL::temporarySignedRoute(
            'projects.timelapse.frame.signed',
            self::linkExpiry(),
            array_filter([
                'frame' => $frame->id,
                'thumb' => $size === 'thumb' ? 1 : null,
                'size' => $size === 'screen' ? 'screen' : null,
                'v' => $frame->version ?: null,
            ]),
        );
    }

    /**
     * The end of tomorrow: good for 24 to 48 hours, and identical all day.
     * `now()->addHours(24)` moved every second, so each refresh of a grid
     * handed out a brand-new address for every photo and nothing a phone
     * had already downloaded was ever found again.
     */
    private static function linkExpiry(): Carbon
    {
        return now()->addDay()->endOfDay();
    }

    /**
     * Width and height of the copy viewers see, upright. Until the queued
     * job writes the sequence copy the archive original stands in (as the
     * frame route does), and that file still carries its rotation in EXIF.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function displayDimensions(ProjectTimelapseFrame $frame): array
    {
        try {
            $disk = Storage::disk($frame->disk);
            $path = $frame->display_path;
            $isArchive = false;

            if (! $disk->exists($path)) {
                if (! $frame->original_path || ! $disk->exists($frame->original_path)) {
                    return [null, null];
                }

                $path = $frame->original_path;
                $isArchive = true;
            }

            $size = @getimagesize($disk->path($path));

            if (! $size) {
                return [null, null];
            }

            [$width, $height] = $size;

            if ($isArchive && (int) ($this->exif($disk->path($path))['Orientation'] ?? 1) >= 5) {
                [$width, $height] = [$height, $width];
            }

            return [(int) $width, (int) $height];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * How this frame was shot, from the archive original's EXIF: the lens
     * (35mm-equivalent focal length — 13mm is a phone's 0.5x, about 24mm its
     * 1x), and the tilt the Hive Contractors app's camera wrote into the
     * comment at the shutter. Null when the file says neither — a frame
     * captured in the browser is a canvas JPEG with no EXIF at all.
     *
     * @return array{focal_length_35mm: int|null, lens: string|null, pitch: float|null, roll: float|null}|null
     */
    private function captureHints(ProjectTimelapseFrame $frame): ?array
    {
        try {
            $disk = Storage::disk($frame->disk);

            if (! $frame->original_path || ! $disk->exists($frame->original_path)) {
                return null;
            }

            $exif = $this->exif($disk->path($frame->original_path));
        } catch (\Throwable) {
            return null;
        }

        $focalLength = (int) ($exif['FocalLengthIn35mmFilm'] ?? 0);
        $note = $this->captureNote($exif);

        $hints = [
            'focal_length_35mm' => $focalLength >= 8 && $focalLength <= 400 ? $focalLength : null,
            'lens' => in_array($note['lens'] ?? null, ['ultra-wide', 'wide', 'tele'], true) ? $note['lens'] : null,
            'pitch' => $this->angle($note['pitch'] ?? null, 90),
            'roll' => $this->angle($note['roll'] ?? null, 45),
        ];

        return array_filter($hints, fn ($value) => $value !== null) === [] ? null : $hints;
    }

    /**
     * The JSON note the app's camera leaves in EXIF UserComment
     * ({"hive":1,"pitch":-2.5,"roll":0.3,"lens":"wide"}). PHP hands the
     * comment back with its 8-byte character-set header still attached, and
     * a decoded copy under COMPUTED — whichever holds the braces wins.
     *
     * @param  array<string, mixed>  $exif
     * @return array<string, mixed>
     */
    private function captureNote(array $exif): array
    {
        foreach ([$exif['COMPUTED']['UserComment'] ?? null, $exif['UserComment'] ?? null] as $comment) {
            if (! is_string($comment) || preg_match('/\{.*\}/s', $comment, $match) !== 1) {
                continue;
            }

            $note = json_decode($match[0], true);

            if (is_array($note) && ($note['hive'] ?? null)) {
                return $note;
            }
        }

        return [];
    }

    private function angle(mixed $value, float $limit): ?float
    {
        if (! is_numeric($value) || abs((float) $value) > $limit) {
            return null;
        }

        return round((float) $value, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function exif(string $absolutePath): array
    {
        if (! function_exists('exif_read_data')) {
            return [];
        }

        $exif = @exif_read_data($absolutePath);

        return is_array($exif) ? $exif : [];
    }
}
