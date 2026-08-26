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
    /**
     * Set whenever an automated sign-in fails (challenge wall or otherwise),
     * cleared the moment a sign-in succeeds or the extension reports a
     * working session. The sidebar reads it to surface "Menards needs a
     * sign-in" — login() forgets the sync-status cache before attempting,
     * so without this flag a failed attempt would leave no visible trace.
     */
    public const NEEDS_SIGNIN_CACHE_KEY = 'menards:needs_signin';

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

        $this->prepareProfileForSessionRestore($profile);
        $this->writeExtensionDefaults();

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

        // Session restore just resurrected every tab from the previous run and
        // the startup URL added one more — collapse back to a single tab.
        sleep(3);
        $this->tidyTabs();

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

    /**
     * Hand the extension its Hive URL and bridge token.
     *
     * The extension seeds chrome.storage from a bundled defaults.json the first
     * time it runs, so writing this file is what removes the last piece of typing
     * from setup — nobody has to enter a 48-character token into an options page
     * through a remote framebuffer. It is written on every start so a rotated
     * token reaches the extension without anyone remembering to update it.
     *
     * Gitignored, and deliberately so: it holds the token in clear text. Anything
     * already in chrome.storage wins, so the options page still overrides it.
     */
    public function writeExtensionDefaults(): void
    {
        $token = (string) config('services.menards.bridge_token');

        if ($token === '') {
            Log::channel('menards')->error(
                'Menards browser: MENARDS_BRIDGE_TOKEN is not set — the extension cannot reach Hive'
            );

            return;
        }

        $path = $this->extensionDir() . '/defaults.json';

        file_put_contents($path, json_encode([
            'serverUrl' => rtrim((string) config('app.url'), '/'),
            'token' => $token,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        @chmod($path, 0600);
    }

    /**
     * Sign the server browser in to menards.com, unattended.
     *
     * Types the account's own credentials into the real browser over X, the same
     * events a keyboard would generate — there is no fingerprint being faked and
     * no captcha being defeated. Menards asks for neither: it serves this browser
     * a normal login form and accepts a normal submission.
     *
     * The password reaches xdotool down a pipe on /dev/stdin, never as an
     * argument and never through a file. Anything on the box can read another
     * process's argv from /proc, and a password written to disk outlives the
     * moment it was needed.
     *
     * Coordinates are hard-coded because the sign-in form has one layout and this
     * runs on one fixed 1280x900 display. They are a guess that is checked: the
     * method only reports success if the browser actually left login.html, so a
     * changed layout fails loudly rather than silently typing into nothing.
     *
     * @return array{ok: bool, error?: string, url?: string, already?: bool}
     */
    public function login(string $email, string $password): array
    {
        if (trim((string) shell_exec('command -v xdotool 2>/dev/null')) === '') {
            return ['ok' => false, 'error' => 'xdotool is not installed — apt install xdotool'];
        }

        if (! $this->processAlive('Xvfb ' . self::DISPLAY)) {
            return ['ok' => false, 'error' => 'The browser is not running — run menards:browser start first.'];
        }

        // Do nothing if the session is still good. Without this check a second
        // call would navigate to login.html, be redirected away by Menards
        // because we are already signed in, and then type an email address and a
        // password into whatever control happened to be under those coordinates
        // on the page it landed on instead.
        if ($this->signedIn()) {
            \Illuminate\Support\Facades\Cache::forget(self::NEEDS_SIGNIN_CACHE_KEY);

            return ['ok' => true, 'url' => $this->windowTitle(), 'already' => true];
        }

        // Establishing a NEW session makes every earlier report about the old
        // one obsolete. Without this the expired-session flag outlives what it
        // describes: on 2026-08-24 a sign-in genuinely succeeded (the browser
        // reached Account Overview) and was still reported as failed, because
        // signedIn() honours that flag before it looks at anything else. A
        // stale fact must not outvote a live one.
        \Illuminate\Support\Facades\Cache::forget(
            \App\Http\Controllers\MenardsSyncStatusController::CACHE_KEY
        );

        if ($this->loadAndWait('https://www.menards.com/main/login.html', ['Sign In at Menards']) === '') {
            if ($this->looksLikeChallengeWall()) {
                $this->flagNeedsSignin('challenge');

                return ['ok' => false, 'error' => 'Imperva is showing a security challenge (hCaptcha). '
                    . 'Nothing here will solve that: either click it once over noVNC, or leave it — '
                    . 'the hourly ensure retries after the score cools down.'];
            }

            $this->flagNeedsSignin('login_failed');

            return ['ok' => false, 'error' => 'The sign-in page never loaded. Last page seen: '
                . ($this->windowTitle() ?: '(none)')];
        }

        // The title arrives with the document, about a second in; the form is
        // rendered by Vue several seconds later. Typing between those two moments
        // sends every keystroke into a page that has no fields yet — which is
        // exactly what waiting only for the title caused.
        sleep(6);

        // Focus the email field, clear whatever a previous attempt left there.
        $this->click(378, 512);
        usleep(800000);
        $this->xdo('key ctrl+a');
        $this->xdo('key Delete');
        usleep(300000);

        $this->xdo('type --delay 40 ' . escapeshellarg($email));
        usleep(800000);

        // Tab rather than a second click: after a validation error the form grows
        // and every field below the email box shifts down by about 26px.
        $this->xdo('key Tab');
        usleep(800000);

        $this->typeSecret($password);
        usleep(800000);

        // Click the button. Enter looks more robust — it does not depend on the
        // button's position — but this form ignores it: swapping the click for
        // Enter turned a working sign-in into four consecutive failures with no
        // error shown on the page, because nothing was ever submitted. Enter
        // stays only as a fallback.
        $this->click(378, 667);

        if (! $this->waitForTitleGone('Sign In at Menards', 12)) {
            $this->xdo('key --clearmodifiers Return');
        }

        // Wait for the submission to actually navigate before touching the
        // browser again. A fixed sleep here either wastes time or, on a slow
        // response, yanks the tab away mid-POST and loses the sign-in.
        $this->waitForTitleGone('Sign In at Menards');

        // Menards lands on Account Overview first and forwards to the receipt
        // page a moment later. Judging immediately catches the intermediate
        // page and calls a successful sign-in a failure.
        $this->waitForTitle(['Receipt Lookup at Menards', 'Account Overview at Menards'], 15);

        if ($this->signedIn()) {
            Log::channel('menards')->info('Menards browser: signed in', ['title' => $this->windowTitle()]);
            \Illuminate\Support\Facades\Cache::forget(self::NEEDS_SIGNIN_CACHE_KEY);

            return ['ok' => true, 'url' => $this->windowTitle()];
        }

        $title = $this->windowTitle();

        Log::channel('menards')->error('Menards browser: sign-in did not take', ['title' => $title]);
        $this->flagNeedsSignin('login_failed');

        return [
            'ok' => false,
            'error' => 'The receipt page is still not reachable after submitting. The credentials may be '
                . 'wrong, or the sign-in form moved. Last page seen: ' . ($title ?: '(none)'),
            'url' => $title,
        ];
    }

    /**
     * Collapse the window to exactly one tab, left on the receipt page.
     *
     * Session restore resurrects every previous tab on each start and appends
     * one more, and years of that turns the tab strip into confetti. There is
     * no CDP here (deliberately — nothing may drive this browser but its own
     * extension and a human), so this works the strip by keyboard: mark tab 1
     * with chrome://version — a local page whose "About Version" title nothing
     * on menards.com or Hive can collide with, loaded with zero network — then
     * close from the far end until only the marker is left, and finally point
     * the survivor back at the receipt page the extension works from.
     */
    public function tidyTabs(): array
    {
        if (! $this->processAlive('Xvfb ' . self::DISPLAY)) {
            return ['ok' => false, 'error' => 'The browser is not running — run menards:browser ensure first.'];
        }

        // A stray modal (a Save File dialog sat on this display for weeks)
        // swallows every keystroke and turns the whole sweep into a no-op
        // that still counts to the bound. Escape is harmless on a normal
        // page — send it twice before touching the strip.
        $this->xdo('key Escape');
        usleep(300000);
        $this->xdo('key Escape');
        usleep(300000);

        $this->xdo('key ctrl+1');
        usleep(400000);
        $this->navigate('chrome://version/');
        sleep(2);

        $closed = 0;
        $markerSeen = false;
        for ($i = 0; $i < 40; $i++) {
            $this->xdo('key ctrl+9');
            usleep(400000);

            if (str_contains($this->windowTitle(), 'About Version')) {
                $markerSeen = true;
                break;
            }

            $this->xdo('key ctrl+w');
            $closed++;
            usleep(400000);
        }

        $this->navigate('https://www.menards.com/main/receiptLookup.html');

        // Never finding the marker means the keystrokes weren't landing (a
        // modal, a lost window) or the pile outran the bound — either way the
        // "closed" count is not to be trusted as a finished job.
        if (! $markerSeen) {
            Log::channel('menards')->warning('Menards browser: tidy never saw its marker tab', ['closed' => $closed]);

            return [
                'ok' => false,
                'closed' => $closed,
                'error' => 'Tidy ran to its bound without finding the marker tab — keystrokes may not be '
                    . 'reaching the browser (a modal dialog?), or the pile exceeds the bound. '
                    . 'Look at the display over the /menards/browser viewer.',
            ];
        }

        Log::channel('menards')->info('Menards browser: tabs tidied', ['closed' => $closed]);

        return ['ok' => true, 'closed' => $closed];
    }

    protected function flagNeedsSignin(string $reason): void
    {
        \Illuminate\Support\Facades\Cache::put(
            self::NEEDS_SIGNIN_CACHE_KEY,
            ['reason' => $reason, 'at' => now()->toIso8601String()],
            now()->addMonth(),
        );
    }

    /**
     * Drive the omnibox rather than the page — works regardless of what is
     * rendered, including an error page with no controls at all.
     *
     * The ctrl+a is not redundant with ctrl+l. Typing too soon after ctrl+l loses
     * the first keystroke while Chrome is still focusing the omnibox, and losing
     * one character turns "https://…" into "ttps://…", which Chrome cannot parse
     * as a URL and hands to the default search engine instead. The browser then
     * sits on a Google results page while every title check quietly fails.
     */
    protected function navigate(string $url): void
    {
        $this->xdo('key ctrl+l');
        usleep(1200000);
        $this->xdo('key --clearmodifiers ctrl+a');
        usleep(300000);
        // Two leading spaces, which Chrome trims. Even with the pauses above,
        // the omnibox intermittently swallows the first keystroke after ctrl+l,
        // and losing one character turns "https://…" into "ttps://…" — not a URL,
        // so Chrome hands it to the default search engine and the browser ends up
        // on a Google results page. A dropped space costs nothing.
        $this->xdo('type --clearmodifiers --delay 25 ' . escapeshellarg('  ' . $url));
        usleep(600000);
        $this->xdo('key --clearmodifiers Return');
    }

    /**
     * Navigate and confirm we arrived somewhere we recognise, retrying if not.
     *
     * Returns the matched window title, or '' if none of $accept showed up. Every
     * caller passes each outcome it can handle — for the receipt page that means
     * both the page itself and the sign-in page Menards redirects to — so
     * "matched nothing" means the navigation itself misfired rather than the
     * session being bad. Retrying is what makes a swallowed keystroke a slower
     * success instead of a wrong answer.
     *
     * @param  array<int, string>  $accept
     */
    protected function loadAndWait(string $url, array $accept, int $attempts = 2, int $seconds = 25): string
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->navigate($url);

            $deadline = time() + $seconds;

            do {
                $title = $this->windowTitle();

                foreach ($accept as $needle) {
                    if (str_contains($title, $needle)) {
                        return $title;
                    }
                }

                usleep(750000);
            } while (time() < $deadline);

            // A Menards page that still has no real title after the full window
            // is almost certainly Imperva's challenge interstitial ("Additional
            // security check is required" — an hCaptcha), which sets no <title>,
            // so Chrome shows the bare URL. Retrying navigation against it is
            // worse than useless: a burst of rapid navigations is exactly the
            // signal that raised the score in the first place, and it re-triggers
            // the load we would otherwise be waiting out. Stop here, say what it
            // is, and leave the next attempt to the hourly schedule — or to a
            // person clicking the checkbox over noVNC. We do not solve captchas.
            if ($this->looksLikeChallengeWall()) {
                Log::channel('menards')->error('Menards browser: Imperva challenge wall is up', [
                    'url' => $url,
                    'title' => $this->windowTitle(),
                ]);

                return '';
            }

            Log::channel('menards')->warning('Menards browser: navigation did not land', [
                'url' => $url,
                'attempt' => $attempt,
                'title' => $this->windowTitle(),
            ]);
        }

        return '';
    }

    /**
     * Every real Menards page titles itself "… at Menards®"; the challenge
     * interstitial sets no title at all, so the window title is the bare URL.
     * "Contains the domain but never the site suffix, well after load" is
     * therefore the wall's signature — reachable without any access to the
     * page's content.
     */
    public function looksLikeChallengeWall(): bool
    {
        $title = $this->windowTitle();

        return str_contains($title, 'menards.com/') && ! str_contains($title, 'at Menards');
    }

    /**
     * Type a secret without it appearing in any process's arguments.
     *
     * xdotool --file reads the text from a path; /dev/stdin lets that path be a
     * pipe we write to directly, so the password exists only in memory shared
     * between these two processes.
     */
    protected function typeSecret(string $secret): void
    {
        $cmd = sprintf(
            'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%s xdotool type --delay 40 --file /dev/stdin',
            escapeshellarg(self::DISPLAY)
        );

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            return;
        }

        fwrite($pipes[0], $secret);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    /**
     * Is the browser's session good enough to reach the receipt page?
     *
     * Asks for a positive signal rather than the absence of a negative one. The
     * first version of this returned "already signed in" whenever the title was
     * not the sign-in page, which on a freshly provisioned server meant a blank
     * "New Tab" — still loading, no session at all — was read as success, and
     * the command reported itself done without having signed in to anything.
     *
     * Only the receipt page itself counts: it is what the extension needs, and
     * Menards redirects it to login.html for anyone without a session.
     */
    public function signedIn(): bool
    {
        // Look before navigating. Navigation is the expensive move here, and not
        // in time — it is what draws Imperva's challenge. On 2026-08-23 a working
        // session (13 receipts fetched at 07:36) was destroyed at 07:58 by a
        // single navigation to this very page: the wall came up, and from then on
        // even the extension's XHR calls got the challenge HTML back instead of
        // JSON. A health check that breaks the health it is checking is worse
        // than no health check.
        //
        // The extension's own last fetch outranks every guess below it. It made
        // a REAL authenticated request; a title is only a hint about one. On
        // 2026-08-23 the extension recorded "the browser session has expired"
        // at 08:01 while this method kept answering true for the rest of the
        // day, so four scheduled syncs ran against a dead session and nothing
        // said so.
        $report = \Illuminate\Support\Facades\Cache::get(
            \App\Http\Controllers\MenardsSyncStatusController::CACHE_KEY
        );

        if (is_array($report) && ($report['session_expired'] ?? false)) {
            return false;
        }

        // If the browser is already sitting on a real Menards page, the session
        // is good and there is nothing to find out.
        $title = $this->windowTitle();

        if (str_contains($title, 'Receipt Lookup at Menards')) {
            return true;
        }

        // Any signed-in Menards page will do as evidence — the account pages all
        // title themselves "… at Menards®" and none of them render for a signed
        // out visitor.
        if (str_contains($title, 'at Menards') && ! str_contains($title, 'Sign In at Menards')) {
            return true;
        }

        // Genuinely unknown (a blank tab, the sign-in page, a challenge). Now a
        // navigation is worth its cost. Both outcomes are accepted so a failure
        // to match means the navigation misfired, not that the session is bad.
        return str_contains(
            $this->loadAndWait(
                'https://www.menards.com/main/receiptLookup.html',
                ['Receipt Lookup at Menards', 'Sign In at Menards']
            ),
            'Receipt Lookup at Menards'
        );
    }

    /** Poll until the title matches any of these, or the deadline passes. */
    protected function waitForTitle(array $needles, int $seconds = 15): bool
    {
        $deadline = time() + $seconds;

        do {
            $title = $this->windowTitle();

            foreach ($needles as $needle) {
                if (str_contains($title, $needle)) {
                    return true;
                }
            }

            usleep(500000);
        } while (time() < $deadline);

        return false;
    }

    /** Poll until the title stops matching — i.e. the page navigated away. */
    protected function waitForTitleGone(string $needle, int $seconds = 25): bool
    {
        $deadline = time() + $seconds;

        do {
            if (! str_contains($this->windowTitle(), $needle)) {
                return true;
            }

            usleep(750000);
        } while (time() < $deadline);

        return false;
    }

    /**
     * What the browser is showing, via the window title.
     *
     * Menards titles the sign-in page "Sign In at Menards®" and every page behind
     * the login something else ("Receipt Lookup at Menards®", "Account Overview
     * at Menards®"), so the title alone separates success from failure. Reading
     * the omnibox instead would be more precise and would cost an xclip
     * dependency for no gain.
     */
    protected function windowTitle(): string
    {
        return trim((string) shell_exec(sprintf(
            'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%s '
            . 'xdotool search --onlyvisible --class chrome getwindowname %%@ 2>/dev/null | head -1',
            escapeshellarg(self::DISPLAY)
        )));
    }

    /**
     * Move the pointer, let it settle, then press.
     *
     * Screen coordinates, not window-relative: the browser window sits at 1,1 on
     * this display, so the two differ by a pixel, and `mousemove --window` adds a
     * lookup that can resolve to the wrong X window when Chrome has more than one.
     *
     * The pause between moving and clicking is not decoration. Chrome decides what
     * is under the cursor from its own tracking of pointer motion, and a move and
     * a press delivered in the same instant can be handled against the previous
     * position.
     */
    protected function click(int $x, int $y): void
    {
        $this->xdo(sprintf('mousemove --sync %d %d', $x, $y));
        usleep(400000);
        $this->xdo('click --clearmodifiers 1');
    }

    protected function xdo(string $args): void
    {
        shell_exec(sprintf(
            'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE DISPLAY=%s xdotool %s 2>/dev/null',
            escapeshellarg(self::DISPLAY),
            $args
        ));
    }

    /** @return array{running: bool, chrome: bool, extension: bool, configured: bool, signed_in: bool, posts_to: string, page: string} */
    public function status(): array
    {
        $cookieDb = $this->userDataDir() . '/Default/Cookies';
        $running = $this->processAlive('Xvfb ' . self::DISPLAY);

        return [
            'running' => $running,
            'chrome' => $this->processAlive('--user-data-dir=' . $this->userDataDir()),
            // Whether the force-install policy actually took. Worth surfacing:
            // a policy naming an extension id that does not match the packaged
            // one fails silently — Chrome starts, the browser looks healthy, and
            // nothing ever syncs.
            'extension' => $this->extensionInstalled(),
            // Whether the extension has been handed a Hive URL and token. start()
            // writes these from config, so an empty MENARDS_BRIDGE_TOKEN at the
            // time of the first start leaves the extension unable to deliver
            // anything — with nothing on screen to say so.
            'configured' => $this->extensionConfigured(),
            // Cheap heuristic only: a sizeable cookie jar means a session was
            // persisted at some point, not that it is still valid. `login`
            // answers the real question by loading the receipt page.
            'signed_in' => is_file($cookieDb) && filesize($cookieDb) > 20480,
            // Where the extension will POST receipts — read back from the file
            // actually handed to it, not from config, so a stale defaults.json
            // (written before APP_URL or the token changed) is visible here
            // instead of being discovered as receipts that never arrive.
            'posts_to' => $this->postsTo(),
            'page' => $running ? $this->windowTitle() : '',
        ];
    }

    public function postsTo(): string
    {
        $path = $this->extensionDir() . '/defaults.json';

        if (! is_file($path)) {
            return '';
        }

        return (string) (json_decode((string) file_get_contents($path), true)['serverUrl'] ?? '');
    }

    /** Has defaults.json been written with a usable Hive URL and token? */
    public function extensionConfigured(): bool
    {
        $path = $this->extensionDir() . '/defaults.json';

        if (! is_file($path)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return ! empty($data['token']) && ! empty($data['serverUrl']);
    }

    /**
     * Is our extension actually loaded in this profile?
     *
     * Never matched on an extension id. The id comes from whichever signing key
     * a given server generated, so it differs per machine — assuming a fixed id
     * is precisely the mistake that let a policy naming one id force-install
     * nothing at all while every other check reported a healthy browser.
     *
     * The two install routes leave different traces, so both are checked:
     *
     *   force-installed by policy — unpacked into <profile>/Default/Extensions/
     *     <id>/<version>/, manifest and all.
     *   loaded unpacked through the UI (how the dev box is set up) — nothing is
     *     copied anywhere. Preferences records the source path and no manifest
     *     at all, so the name is not available and the path is all there is.
     */
    /**
     * The installed extension's id, or null if it is not installed.
     *
     * Resolved from the profile rather than assumed. The id is derived from
     * whichever signing key a given server generated, so it differs per machine
     * — hard-coding one is exactly the mistake that once had a policy
     * force-installing an id that existed nowhere while every check still
     * reported a healthy browser.
     */
    public function extensionId(): ?string
    {
        $profile = $this->userDataDir() . '/Default';

        foreach (glob($profile . '/Extensions/*/*/manifest.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);

            if (str_contains((string) ($data['name'] ?? ''), 'Menards Receipt Sync')) {
                // …/Extensions/<id>/<version>/manifest.json
                return basename(dirname(dirname($manifest)));
            }
        }

        $prefs = $profile . '/Preferences';

        if (! is_file($prefs)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($prefs), true);
        $source = basename($this->extensionDir());

        foreach ($data['extensions']['settings'] ?? [] as $id => $entry) {
            $isOurs = str_contains((string) ($entry['manifest']['name'] ?? ''), 'Menards Receipt Sync')
                || ($source !== '' && str_contains((string) ($entry['path'] ?? ''), $source));

            if ($isOurs) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Ask the extension to fetch receipts now.
     *
     * Opens options.html?sync=1, which the options page treats as "start a run".
     * A URL is the only way in from outside the browser: chrome.runtime
     * messaging is available only to extension pages, and clicking the button by
     * screen coordinates broke on any layout change.
     *
     * Deliberately a chrome-extension:// URL. It does not touch menards.com, so
     * it cannot draw the Imperva challenge, and the fetch itself reuses the
     * receipt tab already open and makes only XHR calls.
     *
     * @return array{ok: bool, error?: string}
     */
    public function requestSync(): array
    {
        if (! $this->processAlive('Xvfb ' . self::DISPLAY)) {
            return ['ok' => false, 'error' => 'The browser is not running — run menards:browser ensure first.'];
        }

        $id = $this->extensionId();

        if (! $id) {
            return ['ok' => false, 'error' => 'The receipt extension is not installed — run scripts/provision-menards-browser.sh.'];
        }

        // A new tab, not the current one: the receipt tab must stay open, since
        // the extension reuses it and reusing costs no navigation at all.
        $this->xdo('key ctrl+t');
        usleep(800000);
        $this->navigate(sprintf('chrome-extension://%s/options.html?sync=1', $id));

        // The page's only job is chrome.runtime.sendMessage({action:'run'}) to
        // the background worker (options.js) — once loaded it is done. Close
        // it, or one of these piles up per scheduled sync, forever.
        sleep(4);
        $this->xdo('key ctrl+w');

        Log::channel('menards')->info('Menards: sync requested', ['extension' => $id]);

        return ['ok' => true];
    }

    public function extensionInstalled(): bool
    {
        $profile = $this->userDataDir() . '/Default';

        foreach (glob($profile . '/Extensions/*/*/manifest.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);

            if (str_contains((string) ($data['name'] ?? ''), 'Menards Receipt Sync')) {
                return true;
            }
        }

        $prefs = $profile . '/Preferences';

        if (! is_file($prefs)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($prefs), true);
        $source = basename($this->extensionDir());

        foreach ($data['extensions']['settings'] ?? [] as $entry) {
            if (str_contains((string) ($entry['manifest']['name'] ?? ''), 'Menards Receipt Sync')) {
                return true;
            }

            if ($source !== '' && str_contains((string) ($entry['path'] ?? ''), $source)) {
                return true;
            }
        }

        return false;
    }

    public function stop(): array
    {
        // Chrome first, and give it time to actually shut down.
        //
        // Menards' session cookie is non-persistent: it survives a restart only
        // because the profile is set to restore the last session, and Chrome
        // only writes that state out during an orderly exit. Killing it
        // alongside everything else left exit_type "Crashed" in the profile and
        // the session gone — which is what made every deploy cost a human
        // clicking through an Imperva challenge.
        $chrome = $this->selfExcludingPattern('--user-data-dir=' . $this->userDataDir());
        @shell_exec('pkill -TERM -f -- ' . escapeshellarg($chrome) . ' 2>/dev/null');

        // Up to ~12s. A cold profile with many tabs needs a few seconds; waiting
        // is far cheaper than the sign-in it protects.
        for ($waited = 0; $waited < 24; $waited++) {
            if (! $this->processAlive('--user-data-dir=' . $this->userDataDir())) {
                break;
            }

            usleep(500000);
        }

        // Only if it ignored the request. SIGKILL is what loses the session, so
        // it is the last resort rather than the default.
        if ($this->processAlive('--user-data-dir=' . $this->userDataDir())) {
            Log::channel('menards')->warning('Menards browser: Chrome did not exit in time, forcing it — the session may be lost');
            @shell_exec('pkill -KILL -f -- ' . escapeshellarg($chrome) . ' 2>/dev/null');
            usleep(500000);
        }

        foreach ([
            'Xvfb ' . self::DISPLAY,
            'x11vnc.*-rfbport ' . self::VNC_PORT,
            'websockify.*' . self::WS_PORT,
        ] as $pattern) {
            @shell_exec('pkill -TERM -f -- ' . escapeshellarg($this->selfExcludingPattern($pattern)) . ' 2>/dev/null');
        }

        usleep(500000);

        return ['ok' => true];
    }

    /**
     * Configure the profile to restore its last session on startup.
     *
     * This is what keeps the Menards login alive across a restart. The cookie is
     * non-persistent, so without restore_on_startup Chrome discards it on exit
     * and the next start needs a human to clear an Imperva challenge before the
     * stored credentials can even reach a login form.
     *
     * Written on every start rather than once by hand: it was set manually on a
     * dev box and never on production, where the setting silently did not exist
     * and every deploy therefore cost a sign-in.
     *
     * exit_type/exited_cleanly are reset too. After a hard kill Chrome shows a
     * "Restore pages?" bubble that sits over the page the extension needs, and
     * on a headless server nobody is there to dismiss it.
     */
    protected function prepareProfileForSessionRestore(string $profile): void
    {
        $path = $profile . '/Default/Preferences';

        if (! is_file($path)) {
            // First run: Chrome writes the file itself, and start() sets the
            // startup URL on the command line anyway.
            return;
        }

        $prefs = json_decode((string) file_get_contents($path), true);

        if (! is_array($prefs)) {
            return;
        }

        // 1 = restore the last session.
        $prefs['session']['restore_on_startup'] = 1;
        $prefs['profile']['exit_type'] = 'Normal';
        $prefs['profile']['exited_cleanly'] = true;

        file_put_contents($path, json_encode($prefs));
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
