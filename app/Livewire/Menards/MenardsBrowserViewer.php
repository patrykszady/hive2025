<?php

namespace App\Livewire\Menards;

use App\Http\Controllers\MenardsSyncStatusController;
use App\Models\Bank;
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
    public function mount(): void
    {
        $this->authorize('viewAny', Bank::class);
    }

    public function retrySignin(): void
    {
        $this->authorize('viewAny', Bank::class);

        dispatch(function () {
            Artisan::call('menards:browser', ['action' => 'ensure']);
        })->onQueue('background');

        session()->flash('menards-retry', 'Sign-in retry queued — give it a minute, then refresh.');
    }

    #[Title('Menards Browser')]
    public function render()
    {
        $syncStatus = Cache::get(MenardsSyncStatusController::CACHE_KEY);
        $needsSignin = Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY);

        return view('livewire.menards.browser-viewer', [
            'syncStatus' => $syncStatus,
            'needsSignin' => $needsSignin,
            'needsAttention' => (bool) ($syncStatus['session_expired'] ?? false) || $needsSignin !== null,
        ]);
    }
}
