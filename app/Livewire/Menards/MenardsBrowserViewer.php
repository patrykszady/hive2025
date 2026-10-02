<?php

namespace App\Livewire\Menards;

use App\Http\Controllers\MenardsSyncStatusController;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The server-side Menards browser, embedded. When Imperva raises its
 * challenge wall no automation gets past it — someone has to click the
 * hCaptcha once. This page puts the noVNC viewer (nginx-proxied websockify,
 * see scripts/nginx-menards-vnc.conf) behind the app's own auth so that
 * click no longer needs an SSH tunnel.
 */
class MenardsBrowserViewer extends Component
{
    /** The viewer saw Imperva's wall on screen during this pause. */
    public const WALL_SEEN_KEY = 'menards:viewer:wall_seen';

    /** A sign-in was queued because the wall went away; one per 10 minutes. */
    public const AUTO_RESUMED_KEY = 'menards:viewer:auto_resumed';

    public function mount(): void
    {
        // One shared, server-side browser signed into one company's Menards
        // account: only that company's admins may view or retry it.
        $this->authorize('menards-browser');
    }

    public function retrySignin(): void
    {
        $this->authorize('menards-browser');

        $this->queueSignin();

        session()->flash('menards-retry', 'Sign-in retry queued — the status below updates on its own.');
    }

    protected function queueSignin(): void
    {
        dispatch(function () {
            // A person asked: sign in even while automatic sign-ins are paused.
            Artisan::call('menards:browser', ['action' => 'ensure', '--manual' => true]);
        })->onQueue('background');
    }

    /**
     * The page polls every 10 seconds while someone watches it, which is
     * exactly while a person works the hCaptcha. Once the wall that was on
     * screen is gone, that person has cleared it: sign in again without
     * waiting for "Retry sign-in" (2026-10-02: the wall was solved at once,
     * Menards answered the stale sign-in with its error page, and the
     * account sat signed out for hours because nobody pressed Retry). Reads
     * the window title only; never navigates.
     *
     * @param  array{reason?: string, at?: string}|null  $needsSignin
     */
    protected function resumeOnceTheWallIsCleared(?array $needsSignin): ?bool
    {
        if (($needsSignin['reason'] ?? null) !== 'challenge' || (string) config('services.menards.chromium_binary') === '') {
            return null;
        }

        $showing = app(MenardsRemoteBrowserService::class)->securityCheckShowing();

        if ($showing === true) {
            Cache::put(self::WALL_SEEN_KEY, true, now()->addHours(MenardsRemoteBrowserService::AUTO_SIGNIN_PAUSE_HOURS));
        } elseif ($showing === false && Cache::pull(self::WALL_SEEN_KEY) && Cache::add(self::AUTO_RESUMED_KEY, true, now()->addMinutes(10))) {
            $this->queueSignin();
        }

        return $showing;
    }

    #[Title('Menards Browser')]
    public function render()
    {
        $syncStatus = Cache::get(MenardsSyncStatusController::CACHE_KEY);
        $needsSignin = Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY);
        $wallShowing = $this->resumeOnceTheWallIsCleared($needsSignin);

        return view('livewire.menards.browser-viewer', [
            'syncStatus' => $syncStatus,
            'needsSignin' => $needsSignin,
            'wallShowing' => $wallShowing,
            'autoResumed' => Cache::has(self::AUTO_RESUMED_KEY),
            'needsAttention' => (bool) ($syncStatus['session_expired'] ?? false) || $needsSignin !== null,
            // The browser stack (Chromium, the extension, the noVNC proxy)
            // exists only on the server; dev mirrors the flags and has no
            // frame to show.
            'hasBrowserStack' => (string) config('services.menards.chromium_binary') !== '',
        ]);
    }
}
