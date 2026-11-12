<?php

namespace App\Support\Menards;

/**
 * The Google Chrome installed on the server, as the Menards receipt browser
 * uses it. Chrome is kept current by a weekly root job in Forge ("Update
 * Google Chrome (Menards browser)": apt-get update + install --only-upgrade
 * google-chrome-stable) — forge itself has no sudo. Imperva scores a browser
 * that is several releases old as a bot: the server sat on 151 while stable
 * reached 157, and the plain "I am human" click stopped clearing the wall.
 *
 * Two questions the app can answer without root:
 *  - is the browser that is running an older Chrome than the one on disk?
 *    (an update replaces the program; a running browser keeps its old copy
 *    until it restarts — `menards:browser ensure --signin` restarts it)
 *  - is the installed Chrome behind what the package source offers?
 *    (the weekly job failed — `menards:browser chrome-check` tells an admin)
 */
class ChromeInstall
{
    /**
     * Whether the running Menards browser's program file was replaced under
     * it: Linux then shows the process's own file as "… (deleted)".
     */
    public function runningBinaryReplaced(): bool
    {
        // The same profile directory MenardsRemoteBrowserService::userDataDir() launches with.
        $profile = config('services.menards.user_data_dir') ?: storage_path('app/menards-browser');
        // "[-]-user…" still matches "--user…", but not this command's own text.
        $pattern = '[-]-user-data-dir=' . preg_quote($profile, '/');

        $pids = preg_split('/\s+/', trim((string) @shell_exec('pgrep -f -- ' . escapeshellarg($pattern) . ' 2>/dev/null'))) ?: [];

        foreach (array_filter($pids, 'ctype_digit') as $pid) {
            if (self::isReplacedChrome(@readlink("/proc/{$pid}/exe"))) {
                return true;
            }
        }

        return false;
    }

    /** What /proc/<pid>/exe reads for a Chrome whose file an update replaced. */
    public static function isReplacedChrome(string|false $exe): bool
    {
        return is_string($exe) && str_contains($exe, 'chrome') && str_ends_with($exe, ' (deleted)');
    }

    /**
     * The installed Chrome and the newest one the package source offers
     * (apt's own lists, refreshed daily by the system and by the weekly job).
     *
     * @return array{installed: ?string, candidate: ?string}
     */
    public function versions(): array
    {
        return self::parsePolicy((string) @shell_exec('apt-cache policy google-chrome-stable 2>/dev/null'));
    }

    /**
     * @return array{installed: ?string, candidate: ?string}
     */
    public static function parsePolicy(string $policy): array
    {
        $read = function (string $label) use ($policy): ?string {
            if (! preg_match('/^\s*' . $label . ':\s*(\S+)/mi', $policy, $match) || $match[1] === '(none)') {
                return null;
            }

            // "157.0.8103.78-1" → "157.0.8103.78"
            return preg_replace('/-\d+$/', '', $match[1]);
        };

        return ['installed' => $read('Installed'), 'candidate' => $read('Candidate')];
    }

    /**
     * How many major releases the installed Chrome is behind the candidate;
     * 0 when current or unknown. A point release behind is 0 too — the
     * weekly job picks that up, and it is not what gets a browser flagged.
     *
     * @param  array{installed: ?string, candidate: ?string}  $versions
     */
    public static function majorsBehind(array $versions): int
    {
        if (! $versions['installed'] || ! $versions['candidate']) {
            return 0;
        }

        return max(0, (int) $versions['candidate'] - (int) $versions['installed']);
    }
}
