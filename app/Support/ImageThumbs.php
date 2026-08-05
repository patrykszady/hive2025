<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Small cached copies of photos, for grids.
 *
 * A phone photo is ~1500×2000; a grid tile is ~120px. Sending the original
 * for every tile — and re-encoding it on every request — is what made photo
 * grids crawl. Each thumbnail is built once, written to disk, and served
 * straight off it from then on.
 */
class ImageThumbs
{
    /** Longest edge of a thumbnail — twice the biggest tile, so retina is sharp. */
    public const MAX_EDGE = 480;

    /**
     * Absolute path to the cached thumbnail for these bytes, generating it on
     * first ask. Null when the source can't be decoded (an unsupported HEIC,
     * a truncated download) — callers fall back to the original.
     *
     * $key must change whenever the source does; callers fold in the file's
     * size or mtime.
     */
    public static function path(string $key, callable $contents): ?string
    {
        $file = self::directory().'/'.sha1($key).'.jpg';

        if (is_file($file)) {
            return $file;
        }

        try {
            File::ensureDirectoryExists(self::directory());

            $image = Image::make($contents());

            // Phone photos carry their rotation in EXIF; the thumbnail has no
            // EXIF to carry it, so bake it in.
            $image->orientate();

            if (max($image->width(), $image->height()) > self::MAX_EDGE) {
                $image->resize(self::MAX_EDGE, self::MAX_EDGE, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

            $image->save($file, 72, 'jpg');
            $image->destroy();

            return $file;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Headers for a thumbnail response — they never change once written. */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800, immutable',
        ];
    }

    protected static function directory(): string
    {
        return storage_path('app/thumbs');
    }
}
