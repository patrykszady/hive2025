<?php

namespace App\Livewire\Ewccv;

use App\Services\EwccvRemoteBrowserService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The EWCCV server browser (EwccvRemoteBrowserService), embedded like the
 * Menards one: the noVNC viewer behind the app's own auth (nginx proxies
 * /ewccv-vnc/ to websockify, scripts/nginx-ewccv-vnc.conf), so watching it
 * needs no SSH tunnel.
 */
#[Title('EWCCV Browser')]
class EwccvBrowserViewer extends Component
{
    public function mount(): void
    {
        $this->authorize('ewccv-browser');
    }

    public function start(): void
    {
        $this->authorize('ewccv-browser');
        dispatch(fn () => Artisan::call('ewccv:browser', ['action' => 'start']))->onQueue('background');
        session()->flash('ewccv-browser', 'Starting the browser — the viewer appears here within a minute.');
    }

    public function stop(): void
    {
        $this->authorize('ewccv-browser');
        dispatch(fn () => Artisan::call('ewccv:browser', ['action' => 'stop']))->onQueue('background');
        session()->flash('ewccv-browser', 'Stopping the browser.');
    }

    public function render()
    {
        $status = app(EwccvRemoteBrowserService::class)->status();

        return view('livewire.ewccv.browser-viewer', [
            'status' => $status,
            'running' => $status['display'] && $status['chrome'],
        ]);
    }
}
