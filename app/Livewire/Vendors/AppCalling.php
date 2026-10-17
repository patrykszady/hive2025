<?php

namespace App\Livewire\Vendors;

use App\Services\Voice\AppVoiceCredentials;
use App\Services\Voice\ClickToCall;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Hive Contractors app's calling, in a desktop browser: signs in with the
 * person's app calling login, rings for their incoming business calls (with
 * their cell), and places outbound calls from the business number through
 * Hive (logged and recorded like click-to-call). The proving ground for the
 * iPhone app, which uses the same logins and the same server side.
 */
#[Title('App Calling')]
class AppCalling extends Component
{
    public string $number = '';

    public function mount(): void
    {
        abort_unless((int) auth()->user()?->primary_vendor_id === 1, 404);
    }

    /**
     * A fresh sign-in token for the browser's calling client.
     *
     * @return array{ok: bool, token?: string, error?: string}
     */
    public function token(): array
    {
        try {
            return ['ok' => true, 'token' => app(AppVoiceCredentials::class)->tokenFor(auth()->user())];
        } catch (\Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => 'Calling could not sign in: '.$exception->getMessage()];
        }
    }

    /**
     * Ring this browser and, once it answers, the number.
     *
     * @return array{ok: bool, error: ?string, call_log_id: ?int}
     */
    public function callOut(): array
    {
        $this->validate(['number' => 'required|string|max:30']);

        $result = app(ClickToCall::class)->start(auth()->user(), [$this->number], ringApp: true);

        return ['ok' => $result['ok'], 'error' => $result['error'], 'call_log_id' => $result['call_log']?->id];
    }

    public function render()
    {
        return view('livewire.vendors.app-calling');
    }
}
