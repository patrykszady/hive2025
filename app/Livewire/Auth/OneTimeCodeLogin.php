<?php

namespace App\Livewire\Auth;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Traits\DetectsDeviceType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Email one-time-code sign-in. The code lives only in the cache, keyed by the
 * address it was mailed to: nothing the browser can read or rewrite decides
 * whether a sign-in succeeds.
 */
class OneTimeCodeLogin extends Component
{
    use DetectsDeviceType;

    /** Wrong guesses allowed before the code is thrown away. */
    private const MAX_ATTEMPTS = 5;

    /** How long a mailed code stays valid, in seconds. */
    private const CODE_TTL = 600;

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

            if ($this->pendingCode() !== null) {
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
     * Generates a code, mails it and stores it for verification. False when
     * the mail could not go out (the error is already on the form).
     */
    private function mailFreshCode(): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Mail::to($this->user->email)->send(new EmailVerificationCode($code));
        } catch (\Throwable $exception) {
            $this->addError('email', 'Unable to send the email right now. Please try again.');
            return false;
        }

        $this->storeVerificationCode($code);

        return true;
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

    private function storeVerificationCode(string $code): void
    {
        Cache::put($this->codeCacheKey(), $code, self::CODE_TTL);
        Cache::forget($this->attemptsCacheKey());
        Cache::put($this->hasSentCacheKey(), true, 86400);
    }

    /**
     * The code currently waiting for this address, if one was mailed and has
     * not expired or been used up.
     */
    private function pendingCode(): ?string
    {
        $code = Cache::get($this->codeCacheKey());

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function codeCacheKey(): string
    {
        return 'otp_login_code:' . $this->email;
    }

    private function attemptsCacheKey(): string
    {
        return 'otp_login_attempts:' . $this->email;
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

        $expected = $this->pendingCode();

        if ($expected === null) {
            $this->addError('verification_code', 'That code has expired. Please request a new one.');
            return;
        }

        if (! hash_equals($expected, $this->verification_code)) {
            $attempts = (int) Cache::get($this->attemptsCacheKey(), 0) + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                Cache::forget($this->codeCacheKey());
                Cache::forget($this->attemptsCacheKey());
                $this->addError('verification_code', 'Too many wrong codes. Please request a new one.');
                return;
            }

            Cache::put($this->attemptsCacheKey(), $attempts, self::CODE_TTL);
            $this->addError('verification_code', 'Invalid verification code.');
            return;
        }

        Cache::forget($this->codeCacheKey());
        Cache::forget($this->attemptsCacheKey());

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
