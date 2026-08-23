<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Keeps a real, signed-in Chromium alive on the server so the Menards receipt
 * extension can run on a schedule with nobody at a keyboard.
 *
 * Requires on the host:
 *   Xvfb        virtual X display
 *   x11vnc      VNC server bound to that display
 *   websockify  HTTP+WS gateway serving the noVNC web client
 *   novnc       the noVNC static files
 *
 * WHY A BROWSER AT ALL
 *
 * menards.com is behind Imperva, which refuses automated clients: a plain HTTP
 * client gets a 930-byte "Request unsuccessful. Incapsula incident ID …" body
 * (served, confusingly, as HTTP 200), and a Puppeteer-driven Chromium gets an
 * unrendered shell. A genuine browser with a session a person established is
 * neither of those things, so it is served normally — and the extension inside
 * it calls Menards' own JSON endpoints rather than scraping the DOM.
 *
 * DELIBERATELY NOT COPIED FROM THE YELP EQUIVALENT
 *
 * gs.construction's YelpRemoteLoginService rotates a residential proxy's exit IP
 * on every attempt so a blocked IP is replaced automatically, and Instagram's
 * login script loads puppeteer-extra-plugin-stealth. Neither is here. This runs
 * the browser honestly from the server's own address, and the fetching is done
 * by an extension rather than over CDP, so there is no automation surface to
 * disguise. If Menards declines to serve this server, that is their answer.
 *
 * ONE-TIME SETUP: start(), open the noVNC URL, sign in to menards.com by hand.
 * The persistent profile keeps that session across restarts; the extension's
 * alarm does the rest.
 */
class MenardsRemoteBrowserService
{
    protected const DISPLAY = ':98';

    protected const SCREEN = '1280x900x24';

    protected const VNC_PORT = 5998;

    protected const WS_PORT = 6098;

    protected function config(): array
    {
        return [
            'chromium' => config('services.menards.chromium_binary')
                ?: (trim((string) shell_exec('command -v chromium 2>/dev/null'))
                    ?: trim((string) shell_exec('command -v chromium-browser 2>/dev/null'))
                    ?: trim((string) shell_exec('command -v google-chrome 2>/dev/null'))),
            'novnc_web' => config('services.menards.novnc_web', '/usr/share/novnc'),
        ];
    }

    public function userDataDir(): string
    {
        return config('services.menards.user_data_dir') ?: storage_path('app/menards-browser');
    }

    public function extensionDir(): string
    {
        return base_path('scripts/menards-receipt-extension');
    }

    /** @return array{ok: bool, missing: array<int, string>} */
    public function checkRequirements(): array
    {
        $cfg = $this->config();
        $missing = [];

        foreach (['Xvfb', 'x11vnc', 'websockify'] as $bin) {
            if (trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) === '') {
                $missing[] = $bin;
            }
        }

        if (! $cfg['chromium']) {
            $missing[] = 'chromium';
        }

        $novnc = rtrim((string) $cfg['novnc_web'], '/');

        if (! is_file($novnc . '/vnc.html') && ! is_file($novnc . '/vnc_lite.html')) {
            $missing[] = 'novnc (' . $novnc . '/vnc.html)';
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * Bring the stack up: Xvfb, Chromium with the extension loaded, x11vnc, and
     * websockify serving noVNC.
     *
     * @return array{ok: bool, error?: string, vnc_url?: string}
     */
    public function start(bool $resetProfile = false): array
    {
        $req = $this->checkRequirements();

        if (! $req['ok']) {
            return [
                'ok' => false,
                'error' => 'Missing host packages: ' . implode(', ', $req['missing'])
                    . '. Install with: sudo apt install xvfb x11vnc novnc websockify chromium-browser',
            ];
        }

        $cfg = $this->config();
        $this->stop();

        $profile = $this->userDataDir();

        if ($resetProfile && is_dir($profile)) {
            // Wiping discards the signed-in session; only on explicit request.
            Log::channel('menards')->warning('Menards browser: wiping profile', ['dir' => $profile]);
            @shell_exec('rm -rf ' . escapeshellarg($profile));
        }

        @mkdir($profile, 0775, true);

        $logDir = storage_path('logs');
        @mkdir($logDir, 0775, true);

        // 1) Virtual display
        $this->spawn(sprintf(
            '%s %s -screen 0 %s -ac -nolisten tcp',
            escapeshellarg(trim((string) shell_exec('command -v Xvfb'))),
            escapeshellarg(self::DISPLAY),
            escapeshellarg(self::SCREEN)
        ), $logDir . '/menards-browser-xvfb.log');

        usleep(700000);

        // 2) Chromium, headed, with the extension loaded and the profile kept.
        //    No --headless, no CDP port: nothing drives this browser but its own
        //    extension and (for the one-time login) a human over VNC.
        $chromeCmd = sprintf(
            // No --load-extension / --disable-extensions-except. Chrome removed the
            // former in 137 (151 also ignores the --disable-features escape hatch),
            // and the latter would suppress the extension we load through the UI
            // instead. The extension is installed once by hand on chrome://extensions
            // with Developer mode on, in the same sitting as the one-time sign-in,
            // and the persistent profile keeps both.
            'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%s %s --user-data-dir=%s '
            . '--no-first-run --no-default-browser-check --disable-session-crashed-bubble '
            . '--window-size=1280,900 --start-maximized %s',
            escapeshellarg(self::DISPLAY),
            escapeshellarg($cfg['chromium']),
            escapeshellarg($profile),
            escapeshellarg('https://www.menards.com/main/receiptLookup.html')
        );

        $chromePid = $this->spawn($chromeCmd, $logDir . '/menards-browser-chrome.log');

        if (! $chromePid) {
            return ['ok' => false, 'error' => 'Chromium failed to start. See storage/logs/menards-browser-chrome.log'];
        }

        usleep(1500000);

        // 3) VNC bound to that display, loopback only — the reverse proxy or an
        //    SSH tunnel is what exposes it, never x11vnc itself.
        $this->spawn(sprintf(
            // -u WAYLAND_DISPLAY: on a host with a Wayland session (WSLg, a desktop
            // distro) x11vnc detects it and exits with "Wayland sessions are as of
            // now only supported via -rawfb", even though we are pointing it at our
            // own Xvfb. Clearing those two vars keeps it looking at :98.
            'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%1$s x11vnc -display %1$s '
            . '-rfbport %2$d -localhost -forever -shared -nopw -quiet',
            escapeshellarg(self::DISPLAY),
            self::VNC_PORT
        ), $logDir . '/menards-browser-x11vnc.log');

        usleep(500000);

        // 4) noVNC over websockify
        $this->spawn(sprintf(
            'websockify --web=%s 127.0.0.1:%d 127.0.0.1:%d',
            escapeshellarg(rtrim((string) $cfg['novnc_web'], '/')),
            self::WS_PORT,
            self::VNC_PORT
        ), $logDir . '/menards-browser-websockify.log');

        Log::channel('menards')->info('Menards browser: started', [
            'display' => self::DISPLAY,
            'chrome_pid' => $chromePid,
            'profile' => $profile,
        ]);

        return [
            'ok' => true,
            'vnc_url' => sprintf('http://127.0.0.1:%d/vnc.html?autoconnect=1&resize=scale', self::WS_PORT),
        ];
    }

    /** @return array{running: bool, chrome: bool, signed_in: bool} */
    public function status(): array
    {
        $cookieDb = $this->userDataDir() . '/Default/Cookies';

        return [
            'running' => $this->processAlive('Xvfb ' . self::DISPLAY),
            'chrome' => $this->processAlive('--user-data-dir=' . $this->userDataDir()),
            // Cheap heuristic only: a sizeable cookie jar means a session was
            // persisted at some point, not that it is still valid.
            'signed_in' => is_file($cookieDb) && filesize($cookieDb) > 20480,
        ];
    }

    public function stop(): array
    {
        foreach ([
            'Xvfb ' . self::DISPLAY,
            'x11vnc.*-rfbport ' . self::VNC_PORT,
            'websockify.*' . self::WS_PORT,
            '--user-data-dir=' . $this->userDataDir(),
        ] as $pattern) {
            @shell_exec('pkill -TERM -f -- ' . escapeshellarg($this->selfExcludingPattern($pattern)) . ' 2>/dev/null');
        }

        usleep(500000);

        return ['ok' => true];
    }

    /**
     * Launch a long-lived process and return immediately.
     *
     * setsid + </dev/null matters: without detaching stdin the child keeps the
     * calling shell's pipe open and shell_exec() blocks until the process exits
     * — which for a browser is "never", so `menards:browser start` would hang.
     */
    protected function spawn(string $cmd, string $logFile): int
    {
        $full = sprintf(
            'setsid nohup %s >> %s 2>&1 < /dev/null & printf %%s "$!"; exit 0',
            $cmd,
            escapeshellarg($logFile)
        );

        return (int) trim((string) shell_exec($full));
    }

    /**
     * `--` before the pattern is required: our chrome pattern starts with
     * "--user-data-dir", which pgrep would otherwise parse as its own option and
     * report the browser as not running.
     */
    protected function processAlive(string $pattern): bool
    {
        return trim((string) shell_exec(
            'pgrep -f -- ' . escapeshellarg($this->selfExcludingPattern($pattern)) . ' 2>/dev/null'
        )) !== '';
    }

    /**
     * Wrap the pattern's first character in a character class.
     *
     * shell_exec() runs these through `sh -c`, and that shell's own command line
     * contains the pattern — so a bare pattern matches the very shell doing the
     * asking. Unguarded, status() reported everything as running even with the
     * display long dead, and stop() killed its own shell before reaching the
     * processes it was aimed at.
     *
     * "[X]vfb :98" still matches the string "Xvfb :98", but no longer matches the
     * literal "[X]vfb :98" sitting in the asking shell's argv.
     */
    protected function selfExcludingPattern(string $pattern): string
    {
        return $pattern === '' ? $pattern : '[' . $pattern[0] . ']' . substr($pattern, 1);
    }
}
