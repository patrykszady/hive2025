<?php

namespace App\Livewire\Menards;

use App\Http\Controllers\MenardsSyncStatusController;
use App\Jobs\RunMenardsBrowserAction;
use App\Services\MenardsRemoteBrowserService;
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

    /** A sign-in or sync was queued because the wall went away; one per 10 minutes. */
    public const AUTO_RESUMED_KEY = 'menards:viewer:auto_resumed';

    /** "Sync now" was pressed; one per 10 minutes. */
    public const SYNC_NOW_KEY = 'menards:viewer:sync_now';

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

    /**
     * Fetch the receipts now instead of at the next scheduled sync, hours off
     * (2026-10-16: three receipts waiting while the wall was being cleared).
     */
    public function syncNow(): void
    {
        $this->authorize('menards-browser');

        if (! Cache::add(self::SYNC_NOW_KEY, true, now()->addMinutes(10))) {
            session()->flash('menards-retry', 'A sync was started in the last 10 minutes — its receipts are on the way.');

            return;
        }

        $this->queueSync();

        session()->flash('menards-retry', 'Sync queued — new receipts arrive within a few minutes; the status below updates on its own.');
    }

    /** A sign-in a person asked for; once it lands it fetches the receipts too (menards:browser ensure --manual). */
    protected function queueSignin(): void
    {
        RunMenardsBrowserAction::dispatch('ensure');
    }

    protected function queueSync(): void
    {
        RunMenardsBrowserAction::dispatch('sync');
    }

    /**
     * The page polls every 10 seconds while someone watches it, which is
     * exactly while a person works the hCaptcha. Once the wall that was on
     * screen is gone, that person has cleared it, so carry on without waiting
     * for a button (2026-10-02: the account sat signed out for hours because
     * nobody pressed Retry): a sign-in wall signs in again (and then
     * fetches); a wall on the receipt API — the last sync met Imperva while
     * signed in — goes straight to a sync, since a fresh sign-in does not
     * clear that one (2026-10-16). Reads the window title only; never
     * navigates.
     *
     * @param  array{reason?: string, at?: string}|null  $needsSignin
     * @param  array{ok?: bool, challenge?: bool}|null  $syncStatus
     */
    protected function resumeOnceTheWallIsCleared(?array $needsSignin, ?array $syncStatus): ?bool
    {
        $signinWall = ($needsSignin['reason'] ?? null) === 'challenge';
        $apiWall = ! $signinWall && $needsSignin === null
            && ($syncStatus['challenge'] ?? false) && ! ($syncStatus['ok'] ?? false);

        if ((! $signinWall && ! $apiWall) || (string) config('services.menards.chromium_binary') === '') {
            return null;
        }

        $showing = app(MenardsRemoteBrowserService::class)->securityCheckShowing();

        if ($showing === true) {
            Cache::put(self::WALL_SEEN_KEY, true, now()->addHours(MenardsRemoteBrowserService::AUTO_SIGNIN_PAUSE_HOURS));
        } elseif ($showing === false && Cache::pull(self::WALL_SEEN_KEY) && Cache::add(self::AUTO_RESUMED_KEY, true, now()->addMinutes(10))) {
            $signinWall ? $this->queueSignin() : $this->queueSync();
        }

        return $showing;
    }

    #[Title('Menards Browser')]
    public function render()
    {
        $syncStatus = Cache::get(MenardsSyncStatusController::CACHE_KEY);
        $needsSignin = Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY);
        $wallShowing = $this->resumeOnceTheWallIsCleared($needsSignin, is_array($syncStatus) ? $syncStatus : null);

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
