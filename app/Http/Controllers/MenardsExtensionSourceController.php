<?php

namespace App\Http\Controllers;

use FilesystemIterator;
use Illuminate\Http\Request;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Lets an admin download the receipt extension's source as a zip, for
 * "Load unpacked" in Edge or Chrome's Developer mode.
 *
 * This is the primary install path for a personal PC: both browsers refuse
 * to force-install a self-hosted extension on a machine that is not joined
 * to a Windows domain, which is what the policy route
 * (MenardsExtensionPolicyController) needs. Load unpacked has no such
 * requirement.
 *
 * defaults.json never ships in this zip — it carries the SERVER browser's
 * proxy credentials, and a personal browser gets its own Hive URL and
 * bridge token typed into the extension's options page by hand instead.
 * tests/ and dotfiles are source-tree noise with nothing there to run them.
 */
class MenardsExtensionSourceController extends Controller
{
    public const EXCLUDED_DIRS = ['tests'];

    public const EXCLUDED_FILES = ['defaults.json'];

    public const ROOT_FOLDER = 'hive-menards-extension';

    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->can('menards-browser'), 403);

        $source = base_path('scripts/menards-receipt-extension');

        if (! is_dir($source)) {
            abort(404);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'menards-ext-') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($this->filesToZip($source) as $relative => $absolute) {
            $zip->addFile($absolute, self::ROOT_FOLDER . '/' . $relative);
        }

        $zip->close();

        return response()->download($zipPath, self::ROOT_FOLDER . '.zip')->deleteFileAfterSend();
    }

    /** @return array<string, string> relative path (forward slashes) => absolute path */
    public function filesToZip(string $source): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', ltrim(
                substr($file->getPathname(), strlen($source)),
                '/'
            ));
            $topSegment = explode('/', $relative)[0];

            if (in_array($topSegment, self::EXCLUDED_DIRS, true)) {
                continue;
            }

            if (in_array(basename($relative), self::EXCLUDED_FILES, true)) {
                continue;
            }

            // Dotfiles anywhere in the path (.gitignore, etc.) are source-tree
            // noise, never something the extension itself needs to run.
            if (str_starts_with(basename($relative), '.')) {
                continue;
            }

            $files[$relative] = $file->getPathname();
        }

        return $files;
    }
}
