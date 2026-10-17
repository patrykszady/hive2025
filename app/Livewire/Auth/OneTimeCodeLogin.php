<?php

namespace App\Livewire\Auth;

use App\Enums\OneTimeLoginCodeResult;
use App\Models\User;
use App\Services\Auth\OneTimeLoginCodes;
use App\Traits\DetectsDeviceType;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Email one-time-code sign-in. The code lives only in the cache, keyed by the
 * address it was mailed to: nothing the browser can read or rewrite decides
 * whether a sign-in succeeds.
 *
 * Code generation/mailing/verification is delegated to
 * App\Services\Auth\OneTimeLoginCodes, the same service the app's
 * POST /api/app/v1/login{,/code} endpoints use — this component only owns
 * the UI-facing step/cooldown state and the passkey-redirect flow.
 */
class OneTimeCodeLogin extends Component
{
    use DetectsDeviceType;

    #[Url]
    public string $email = '';

    #[Locked]
    public string $step = 'request'; // request, verify_code

    public string $verification_code = '';

    #[Locked]
    public ?User $user = null;

    public int $resend_countdown = 0;

    public bool $can_resend = false;

    public string $success_message = '';

    public function mount(): void
    {
        $this->can_resend = false;
        $this->resend_countdown = 0;

        if (!$this->email) {
            $this->email = (string) session('one_time_login_email', '');
        }

        if (!$this->email) {
            $this->email = (string) request()->query('email', '');
        }

        if ($this->email) {
            if (session('one_time_login_force_send')) {
                session()->forget('one_time_login_force_send');
                $this->sendCode();
                return;
            }

            if (app(OneTimeLoginCodes::class)->hasPendingCode($this->email)) {
                $this->step = 'verify_code';
                $this->setResendCooldownFromCache();
            }
        }
    }

    public function sendCode(): void
    {
        $this->validate([
            'email' => 'required|email',
        ]);

        $this->user = User::where('email', $this->email)->first();

        if (!$this->user) {
            $this->addError('email', 'No account found with this email.');
            return;
        }

        if (! $this->mailFreshCode()) {
            return;
        }

        $this->step = 'verify_code';
        $this->startResendCooldown();
        $this->success_message = 'Verification code sent to your email!';
    }

    public function resendCode(): void
    {
        if (!$this->can_resend) {
            return;
        }

        if ($this->isWithinCooldown()) {
            $this->setResendCooldownFromCache();
            return;
        }

        if (!$this->user && $this->email) {
            $this->user = User::where('email', $this->email)->first();
        }

        if (!$this->user) {
            $this->addError('email', 'No account found with this email.');
            $this->step = 'request';
            return;
        }

        if (! $this->mailFreshCode()) {
            return;
        }

        $this->startResendCooldown();
        $this->success_message = 'New verification code sent!';
    }

    /**
     * Mail a fresh code via the shared service. False when the mail could
     * not go out (the error is already on the form).
     */
    private function mailFreshCode(): bool
    {
        if (! app(OneTimeLoginCodes::class)->issue($this->user)) {
            $this->addError('email', 'Unable to send the email right now. Please try again.');
            return false;
        }

        return true;
    }

    private function startResendCooldown(): void
    {
        $this->resend_countdown = 60;
        $this->can_resend = false;
        $this->dispatch('start-countdown', countdown: $this->resend_countdown);
    }

    private function setResendCooldownFromCache(): void
    {
        $remaining = app(OneTimeLoginCodes::class)->remainingCooldown($this->email);

        $this->resend_countdown = $remaining;
        $this->can_resend = $remaining <= 0;

        if ($remaining > 0) {
            $this->dispatch('start-countdown', countdown: $remaining);
        }
    }

    private function isWithinCooldown(): bool
    {
        return app(OneTimeLoginCodes::class)->withinCooldown($this->email);
    }

    public function decrementCountdown(): void
    {
        if ($this->resend_countdown > 0) {
            $this->resend_countdown--;
        }

        if ($this->resend_countdown <= 0) {
            $this->can_resend = true;
        }
    }

    public function enableResend(): void
    {
        $this->resend_countdown = 0;
        $this->can_resend = true;
    }

    public function verifyCode(): void
    {
        $this->validate([
            'verification_code' => 'required|digits:6',
        ]);

        if (!$this->user && $this->email) {
            $this->user = User::where('email', $this->email)->first();
        }

        if (!$this->user) {
            $this->addError('email', 'No account found with this email.');
            $this->step = 'request';
            return;
        }

        $result = app(OneTimeLoginCodes::class)->verify($this->email, $this->verification_code);

        if ($result !== OneTimeLoginCodeResult::Valid) {
            $this->addError('verification_code', $result->message());
            return;
        }

        // Log the user in
        Auth::login($this->user, remember: true);

        session()->flash('message', 'Welcome back!');

        $hasPasskeyForDevice = Auth::user()->webAuthnCredentials()
            ->whereNull('disabled_at')
            ->where('device_type', $this->currentDeviceType())
            ->exists();

        if (! $hasPasskeyForDevice) {
            $this->redirect(route('passkey.setup'), navigate: true);
            return;
        }

        $this->redirectIntended(default: route('dashboard'), navigate: true);
    }

    #[Title('One-Time Code Login')]
    public function render()
    {
        return view('livewire.auth.one-time-code-login')->layout('components.layouts.guest');
    }
}
