<?php

namespace App\Livewire\Auth;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Traits\DetectsDeviceType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class OneTimeCodeLogin extends Component
{
    use DetectsDeviceType;
    #[Url]
    public string $email = '';

    public string $step = 'request'; // request, verify_code
    public string $verification_code = '';
    public string $generated_code = '';
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

            $cachedCode = Cache::get($this->codeCacheKey());
            if (is_string($cachedCode) && $cachedCode !== '') {
                $this->generated_code = $cachedCode;
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

        $this->generated_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Mail::to($this->user->email)->send(new EmailVerificationCode($this->generated_code));
        } catch (\Throwable $exception) {
            $this->addError('email', 'Unable to send the email right now. Please try again.');
            return;
        }

        $this->storeVerificationCode();
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

        $this->generated_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Mail::to($this->user->email)->send(new EmailVerificationCode($this->generated_code));
        } catch (\Throwable $exception) {
            $this->addError('email', 'Unable to send the email right now. Please try again.');
            return;
        }

        $this->storeVerificationCode();
        $this->startResendCooldown();
        $this->success_message = 'New verification code sent!';
    }

    private function startResendCooldown(): void
    {
        $this->resend_countdown = 60;
        $this->can_resend = false;
        Cache::put($this->lastSentCacheKey(), now()->timestamp, 60);
        $this->dispatch('start-countdown', countdown: $this->resend_countdown);
    }

    private function setResendCooldownFromCache(): void
    {
        $lastSent = Cache::get($this->lastSentCacheKey());
        if (!is_int($lastSent)) {
            $this->resend_countdown = 0;
            $this->can_resend = true;
            return;
        }

        $elapsed = now()->timestamp - $lastSent;
        $remaining = max(0, 60 - $elapsed);
        $this->resend_countdown = $remaining;
        $this->can_resend = $remaining <= 0;

        if ($remaining > 0) {
            $this->dispatch('start-countdown', countdown: $remaining);
        }
    }

    private function isWithinCooldown(): bool
    {
        $lastSent = Cache::get($this->lastSentCacheKey());
        if (!is_int($lastSent)) {
            return false;
        }

        return (now()->timestamp - $lastSent) < 60;
    }

    private function storeVerificationCode(): void
    {
        Cache::put($this->codeCacheKey(), $this->generated_code, 600);
        Cache::put($this->hasSentCacheKey(), true, 86400);
    }

    private function codeCacheKey(): string
    {
        return 'otp_login_code:' . $this->email;
    }

    private function lastSentCacheKey(): string
    {
        return 'otp_login_last_sent:' . $this->email;
    }

    private function hasSentCacheKey(): string
    {
        return 'otp_login_has_sent:' . $this->email;
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

        if ($this->generated_code === '') {
            $cachedCode = Cache::get($this->codeCacheKey());
            if (is_string($cachedCode)) {
                $this->generated_code = $cachedCode;
            }
        }

        if ($this->verification_code !== $this->generated_code) {
            $this->addError('verification_code', 'Invalid verification code.');
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
