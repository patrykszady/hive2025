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
     * Longest edge of a phone-screen copy. A phone shows a photo about 390pt
     * wide at 3x, so 1280px is as sharp as that screen gets — at roughly a
     * third of the bytes of the 1920px frame behind it.
     */
    public const SCREEN_EDGE = 1280;

    /** JPEG quality of a phone-screen copy — looked at full width, so above the grid thumbnail's 72. */
    public const SCREEN_QUALITY = 80;

    /**
     * Absolute path to the cached thumbnail for these bytes, generating it on
     * first ask. Null when the source can't be decoded (an unsupported HEIC,
     * a truncated download) — callers fall back to the original.
     *
     * $key must change whenever the source does; callers fold in the file's
     * size or mtime. $maxEdge and $quality default to the grid thumbnail;
     * pass SCREEN_EDGE / SCREEN_QUALITY for a phone-screen copy.
     */
    public static function path(string $key, callable $contents, int $maxEdge = self::MAX_EDGE, int $quality = 72): ?string
    {
        $file = self::fileFor($key, $maxEdge);

        if (is_file($file)) {
            return $file;
        }

        try {
            File::ensureDirectoryExists(self::directory());

            $image = Image::make($contents());

            // Phone photos carry their rotation in EXIF; the thumbnail has no
            // EXIF to carry it, so bake it in.
            $image->orientate();

            if (max($image->width(), $image->height()) > $maxEdge) {
                $image->resize($maxEdge, $maxEdge, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

            // Written beside the final name and renamed into place: a second
            // request arriving mid-write must find either no file or a whole
            // one, never the first half of a JPEG.
            $partial = $file.'.'.bin2hex(random_bytes(4)).'.part';
            $image->save($partial, $quality, 'jpg');
            $image->destroy();
            rename($partial, $file);

            return $file;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Where the copy for this key and size lives. The 480px grid thumbnail
     * keeps the name it always had, so copies built before other sizes
     * existed are still found (thumbFileFor() relies on that too).
     */
    protected static function fileFor(string $key, int $maxEdge): string
    {
        $name = $maxEdge === self::MAX_EDGE ? sha1($key) : sha1($key.'@'.$maxEdge);

        return self::directory().'/'.$name.'.jpg';
    }

    /** Longest edge of a micro preview — a handful of pixels, enough to blur up from. */
    public const MICRO_EDGE = 24;

    /**
     * The cached thumbnail file for this key, if one was already built —
     * lets micro previews derive from the 480px thumb instead of decoding
     * the full photo again.
     */
    public static function thumbFileFor(string $key): ?string
    {
        $file = self::directory().'/'.sha1($key).'.jpg';

        return is_file($file) ? $file : null;
    }

    /**
     * A base64 data-URI micro preview (~24px) for these bytes, cached on
     * disk like the thumbnails. Inlined into the page under each grid tile:
     * blurred up, it gives the real image something to fade in over instead
     * of popping out of a blank square. Null when the source can't be read.
     */
    public static function microDataUri(string $key, callable $contents): ?string
    {
        $file = self::directory().'/micro/'.sha1($key).'.jpg';

        if (! is_file($file)) {
            try {
                File::ensureDirectoryExists(dirname($file));

                $image = Image::make($contents());
                $image->orientate();
                $image->resize(self::MICRO_EDGE, self::MICRO_EDGE, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });

                $image->save($file, 40, 'jpg');
                $image->destroy();
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        }

        $bytes = @file_get_contents($file);

        return $bytes === false || $bytes === ''
            ? null
            : 'data:image/jpeg;base64,'.base64_encode($bytes);
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
