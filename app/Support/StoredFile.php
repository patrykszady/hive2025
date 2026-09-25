<?php

namespace App\Support;

/**
 * Resolves a user-supplied file name inside one storage folder and refuses
 * anything that would escape it: `..` segments, absolute paths, symlinks that
 * point outside, NUL bytes. Tries the exact, lower-case and upper-case
 * spellings because the file folders hold mixed-case names.
 */
final class StoredFile
{
    /**
     * The real path of $relative inside $baseDir, or null when nothing safe
     * exists there. With $allowSubdirectories false a name containing a
     * directory separator is refused outright.
     */
    public static function resolve(string $baseDir, string $relative, bool $allowSubdirectories = false): ?string
    {
        $base = realpath($baseDir);

        if ($base === false || ! is_dir($base)) {
            return null;
        }

        $relative = trim(str_replace('\\', '/', $relative), " \t\n\r/");

        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        if (! $allowSubdirectories && str_contains($relative, '/')) {
            return null;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        foreach (array_unique([$relative, strtolower($relative), strtoupper($relative)]) as $candidate) {
            $real = realpath($base.DIRECTORY_SEPARATOR.$candidate);

            if ($real !== false && is_file($real) && str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }
}
