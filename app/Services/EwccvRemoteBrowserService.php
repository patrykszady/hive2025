<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * A real Chrome on the server, parked on ewccv.com with the Hive EWCCV Session
 * Bridge extension in it, so workers comp lookups run with nobody's own
 * browser open (Patryk, 2026-10-14).
 *
 * EWCCV's search sits behind reCAPTCHA v3 Enterprise. On 2026-08-16 tokens
 * minted by EWCCV's own page inside a PUPPETEER-driven Chrome were refused,
 * headless and headed, even on a residential IP: Google scores an automated
 * session too low. NCCI will not issue a bypass key. What has not been tried
 * is a Chrome nobody drives: launched like a person's browser — no DevTools
 * port, no automation flags — with the extension minting inside it, exactly
 * as it does in Patryk's own Chrome. This is that experiment, built like the
 * Menards receipt browser (MenardsRemoteBrowserService) on its own display.
 *
 * The extension signs itself in (it asks Hive for EWCCV's emailed link) and
 * polls Hive every minute while the ewccv.com tab is open. A person does one
 * thing, once, through the viewer: chrome://extensions → Developer mode →
 * Load unpacked → scripts/ewccv-session-bridge. Its Hive URL and token come
 * from defaults.json, written here on every start.
 */
class EwccvRemoteBrowserService
{
    /** :97 is the indexing browser's, :98 Menards'. */
    public const DISPLAY = ':96';

    public const SCREEN = '1280x900x24';

    public const VNC_PORT = 5996;

    public const WS_PORT = 6096;

    public const PARK_URL = 'https://www.ewccv.com/cvs/search';

    public function userDataDir(): string
    {
        return storage_path('app/ewccv-browser');
    }

    public function extensionDir(): string
    {
        return base_path('scripts/ewccv-session-bridge');
    }

    public function chromeBinary(): string
    {
        return (string) (config('services.menards.chromium_binary')
            ?: trim((string) shell_exec('command -v google-chrome-stable 2>/dev/null'))
            ?: trim((string) shell_exec('command -v google-chrome 2>/dev/null')));
    }

    /**
     * Chrome as a person would run it: its own profile, the EWCCV search page,
     * and nothing that marks it as automated — no --remote-debugging-port, no
     * --enable-automation, no --headless.
     */
    public function chromeCommand(): string
    {
        return sprintf(
            'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%s %s --user-data-dir=%s '
            .'--no-first-run --no-default-browser-check --disable-session-crashed-bubble '
            .'--window-size=1280,900 --start-maximized %s',
            escapeshellarg(self::DISPLAY),
            escapeshellarg($this->chromeBinary()),
            escapeshellarg($this->userDataDir()),
            escapeshellarg(self::PARK_URL),
        );
    }

    /** @return list<string> host packages that are missing */
    public function missingRequirements(): array
    {
        $missing = [];
        foreach (['Xvfb', 'x11vnc', 'websockify'] as $bin) {
            if (trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null')) === '') {
                $missing[] = $bin;
            }
        }
        if ($this->chromeBinary() === '') {
            $missing[] = 'google-chrome';
        }

        return $missing;
    }

    /** @return array{ok: bool, error?: string, vnc_url?: string} */
    public function start(): array
    {
        if (($missing = $this->missingRequirements()) !== []) {
            return ['ok' => false, 'error' => 'Missing on this host: '.implode(', ', $missing)];
        }

        $this->stop();
        @mkdir($this->userDataDir(), 0775, true);
        $this->restoreSessionOnStartup();
        $this->writeExtensionDefaults();
        $logs = storage_path('logs');

        $this->spawn(sprintf('%s %s -screen 0 %s -nolisten tcp', escapeshellarg(trim((string) shell_exec('command -v Xvfb'))), escapeshellarg(self::DISPLAY), self::SCREEN), $logs.'/ewccv-browser-xvfb.log');
        usleep(800000);

        $chromePid = $this->spawn($this->chromeCommand(), $logs.'/ewccv-browser-chrome.log');
        if (! $chromePid) {
            return ['ok' => false, 'error' => 'Chrome did not start; see storage/logs/ewccv-browser-chrome.log'];
        }
        usleep(1500000);

        // VNC on loopback only; an SSH tunnel is the way in.
        $this->spawn(sprintf('env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%1$s x11vnc -display %1$s -rfbport %2$d -localhost -forever -shared -nopw -quiet', escapeshellarg(self::DISPLAY), self::VNC_PORT), $logs.'/ewccv-browser-x11vnc.log');
        usleep(500000);
        $this->spawn(sprintf('websockify --web=%s 127.0.0.1:%d 127.0.0.1:%d', escapeshellarg((string) config('services.menards.novnc_web', '/usr/share/novnc')), self::WS_PORT, self::VNC_PORT), $logs.'/ewccv-browser-websockify.log');

        Log::channel('ewccv')->info('EWCCV server browser: started', ['display' => self::DISPLAY, 'chrome_pid' => $chromePid]);

        return ['ok' => true, 'vnc_url' => sprintf('http://127.0.0.1:%d/vnc.html?autoconnect=1&resize=scale', self::WS_PORT)];
    }

    /** Chrome first and gently (an orderly exit keeps the session), then the display stack. */
    public function stop(): array
    {
        $chrome = '--user-data-dir='.$this->userDataDir();
        @shell_exec('pkill -TERM -f -- '.escapeshellarg($this->selfExcluding($chrome)).' 2>/dev/null');
        for ($i = 0; $i < 20 && $this->processAlive($chrome); $i++) {
            usleep(500000);
        }
        if ($this->processAlive($chrome)) {
            @shell_exec('pkill -KILL -f -- '.escapeshellarg($this->selfExcluding($chrome)).' 2>/dev/null');
        }
        foreach (['Xvfb '.self::DISPLAY, 'x11vnc.*-rfbport '.self::VNC_PORT, 'websockify.*'.self::WS_PORT] as $pattern) {
            @shell_exec('pkill -TERM -f -- '.escapeshellarg($this->selfExcluding($pattern)).' 2>/dev/null');
        }

        return ['ok' => true];
    }

    /** @return array{display: bool, chrome: bool, vnc: bool, viewer: bool, extension_configured: bool} */
    public function status(): array
    {
        return [
            'display' => $this->processAlive('Xvfb '.self::DISPLAY),
            'chrome' => $this->processAlive('--user-data-dir='.$this->userDataDir()),
            'vnc' => $this->processAlive('x11vnc.*-rfbport '.self::VNC_PORT),
            'viewer' => $this->processAlive('websockify.*'.self::WS_PORT),
            'extension_configured' => is_file($this->extensionDir().'/defaults.json'),
        ];
    }

    /** A PNG of the display, to see what the browser shows without the viewer. */
    public function screenshot(): ?string
    {
        $path = storage_path('logs/ewccv-browser-screen.png');
        @shell_exec(sprintf('env -u WAYLAND_DISPLAY DISPLAY=%s import -window root %s 2>/dev/null', escapeshellarg(self::DISPLAY), escapeshellarg($path)));

        return is_file($path) ? $path : null;
    }

    /**
     * The extension's Hive URL and bridge token, bundled as defaults.json
     * (gitignored: it holds the token) so nobody types a token through a
     * remote framebuffer. Rewritten on every start so a rotation reaches it.
     */
    public function writeExtensionDefaults(): bool
    {
        $token = (string) config('services.ewccv.bridge_token');
        if ($token === '') {
            Log::channel('ewccv')->error('EWCCV server browser: EWCCV_BRIDGE_TOKEN is not set, the extension cannot reach Hive');

            return false;
        }
        $path = $this->extensionDir().'/defaults.json';
        file_put_contents($path, json_encode(['serverUrl' => rtrim((string) config('app.url'), '/'), 'token' => $token], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        @chmod($path, 0600);

        return true;
    }

    /** Restore the last session on startup, and no "Restore pages?" bubble after a hard stop. */
    protected function restoreSessionOnStartup(): void
    {
        $path = $this->userDataDir().'/Default/Preferences';
        $prefs = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($prefs)) {
            return;
        }
        $prefs['session']['restore_on_startup'] = 1;
        $prefs['profile']['exit_type'] = 'Normal';
        $prefs['profile']['exited_cleanly'] = true;
        file_put_contents($path, json_encode($prefs));
    }

    protected function spawn(string $command, string $logFile): int
    {
        return (int) trim((string) shell_exec(sprintf('nohup setsid %s >> %s 2>&1 < /dev/null & echo $!', $command, escapeshellarg($logFile))));
    }

    protected function processAlive(string $pattern): bool
    {
        return trim((string) shell_exec('pgrep -f -- '.escapeshellarg($this->selfExcluding($pattern)).' 2>/dev/null')) !== '';
    }

    /** "[X]vfb"-style, so pgrep/pkill never match the shell that runs them. */
    protected function selfExcluding(string $pattern): string
    {
        return preg_replace('/^(-*)(.)/', '$1[$2]', $pattern, 1);
    }
}
