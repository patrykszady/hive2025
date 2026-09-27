<?php

namespace App\Services\Citations;

use App\Models\Citation;

/**
 * Adapted from gsc's/dawnsellshomes' app/Services/Citations/
 * CitationSessionService.php, cut down to what is actually true on this
 * host: there is no Xvfb → headed Chromium → x11vnc → noVNC pipeline here
 * at all, and none of Puppeteer's packages are installed in this
 * worktree's node_modules (package.json lists them for the unrelated
 * Menards remote-browser feature — see
 * App\Services\MenardsRemoteBrowserService — but `npm install` was never
 * run here). Standing up the rest of that pipeline
 * (process spawning, port waiting, orphan cleanup, a signed noVNC viewer
 * route) with nothing that can ever succeed behind it would be dead code
 * pretending to be a feature, so it was not ported. checkRequirements()
 * is the honest part: it reports exactly what is missing on THIS host,
 * plainly, so the admin never has to guess why "Start" does nothing.
 *
 * Every other method here answers as a session that is never running,
 * so ss-systems' Citations screen (App\Livewire\Admin\Citations) renders
 * calmly: the board, the canonical payload and the manual status/URL/note
 * form all work; "Start"/"Run all automatically" explain themselves
 * instead of hanging or 500ing.
 */
class CitationSessionService
{
    public const RUNNER = 'scripts/citations/run.mjs';

    public function __construct(protected string $stateFile = '')
    {
        $this->stateFile = $stateFile ?: rtrim((string) config('citations.storage_dir', storage_path('app/citations')), '/').'/session.json';
    }

    /**
     * What a real remote-browser session would need on this host. Always
     * checked fresh (never cached) — an ops change installing these
     * packages should be visible on the very next request.
     *
     * @return array{ok: bool, missing: list<string>}
     */
    public function checkRequirements(bool $headless = false): array
    {
        $cfg = (array) config('citations.session');
        $bins = [$cfg['node_binary'] ?? 'node'];
        if (! $headless) {
            $bins = array_merge($bins, [$cfg['xvfb_binary'] ?? 'Xvfb', $cfg['x11vnc_binary'] ?? 'x11vnc', $cfg['websockify_binary'] ?? 'websockify']);
        }
        $missing = [];
        foreach ($bins as $bin) {
            $found = trim((string) @shell_exec('command -v '.escapeshellarg((string) $bin).' 2>/dev/null'));
            if ($found === '') {
                $missing[] = (string) $bin;
            }
        }
        if (! is_file(base_path(self::RUNNER))) {
            $missing[] = self::RUNNER;
        }
        // Listed in package.json (for the unrelated Menards feature) but
        // never installed into this worktree's node_modules — running
        // `npm install` here is an explicit ops decision, same restraint
        // dawnsellshomes documents for its own port of this class.
        foreach (['puppeteer', 'puppeteer-extra', 'puppeteer-extra-plugin-stealth'] as $package) {
            if (! is_dir(base_path('node_modules/'.$package))) {
                $missing[] = 'node package '.$package;
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    public function dirFor(Citation $citation): string
    {
        $dir = rtrim((string) config('citations.storage_dir', storage_path('app/citations')), '/').'/'.$citation->slug;
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Always refuses: see this class's docblock for why. Returns the same
     * ['ok' => false, 'error' => …] shape a real failed start would, so
     * the controller's error handling needs no special case.
     *
     * @return array{ok: bool, error?: string}
     */
    public function start(Citation $citation, bool $headless = false, bool $auto = false): array
    {
        $req = $this->checkRequirements($headless);

        return ['ok' => false, 'error' => 'Browser automation is not set up on this host. Missing: '.implode(', ', $req['missing']).'.'];
    }

    /** Never running — there is no session to report on. */
    public function status(): array
    {
        return ['running' => false, 'slug' => null, 'runner' => null];
    }

    /** Nothing to resume — start() never leaves a session waiting on a human step. */
    public function resume(Citation $citation): bool
    {
        return false;
    }

    /** Nothing to stop; kept so the controller's stop action has something to call. */
    public function stop(): array
    {
        return ['ok' => true];
    }

    /** No runner ever writes state for this class to fold in; the row is returned unchanged. */
    public function syncCitation(Citation $citation): Citation
    {
        return $citation;
    }

    /** No runner log exists. */
    public function tailLog(int $bytes = 4000): string
    {
        return '';
    }
}
