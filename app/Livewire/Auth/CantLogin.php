<?php

namespace App\Livewire\Auth;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Account recovery by emailed or texted code. The code and the "code was
 * verified" marker live only in the cache, keyed by the account: the browser
 * cannot read the code, pick a different account, or skip to the password
 * step.
 */
class CantLogin extends Component
{
    /** Wrong guesses allowed before the code is thrown away. */
    private const MAX_ATTEMPTS = 5;

    /** How long a code, and a verified marker, stay valid, in seconds. */
    private const CODE_TTL = 600;

    public string $identifier = '';

    #[Locked]
    public string $step = 'identifier'; // identifier, verify_code, reset_password

    public string $verification_code = '';

    #[Locked]
    public ?User $user = null;

    public string $password = '';

    public string $password_confirmation = '';

    public int $resend_countdown = 0;

    public bool $can_resend = false;

    protected $rules = [
        'identifier' => 'required|string',
        'verification_code' => 'required|digits:6',
        'password' => 'required|min:8|confirmed',
    ];

    public function sendVerificationCode()
    {
        $this->validate(['identifier' => 'required']);

        // Find user by email or phone
        $this->user = User::where('email', $this->identifier)
                         ->orWhere('cell_phone', $this->identifier)
                         ->first();

        if (!$this->user) {
            $this->addError('identifier', 'No account found with this email or phone number.');
            return;
        }

        $this->issueCode();

        $this->step = 'verify_code';
        $this->startResendCooldown();
        session()->flash('message', 'Verification code sent!');
    }

    public function resendVerificationCode()
    {
        if (!$this->can_resend) {
            return;
        }

        if (!$this->user) {
            $this->step = 'identifier';
            return;
        }

        $this->issueCode();

        $this->startResendCooldown();
        session()->flash('message', 'New verification code sent!');
    }

    /**
     * Generates a fresh code for the account, stores it and sends it by email
     * or SMS depending on what the person typed.
     */
    private function issueCode(): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeCacheKey(), $code, self::CODE_TTL);
        Cache::forget($this->attemptsCacheKey());
        Cache::forget($this->verifiedCacheKey());

        if (filter_var($this->identifier, FILTER_VALIDATE_EMAIL)) {
            Mail::to($this->user->email)->send(new EmailVerificationCode($code));
        } else {
            $this->sendSMSVerification($code);
        }
    }

    private function startResendCooldown()
    {
        $this->resend_countdown = 60; // 60 seconds
        $this->can_resend = false;

        // Start countdown using browser polling
        $this->dispatch('start-countdown', countdown: $this->resend_countdown);
    }

    public function decrementCountdown()
    {
        if ($this->resend_countdown > 0) {
            $this->resend_countdown--;
        }

        if ($this->resend_countdown <= 0) {
            $this->can_resend = true;
        }
    }

    public function verifyCode()
    {
        $this->validate(['verification_code' => 'required|digits:6']);

        if (!$this->user) {
            $this->step = 'identifier';
            $this->addError('identifier', 'Start over and request a new code.');
            return;
        }

        $expected = Cache::get($this->codeCacheKey());

        if (!is_string($expected) || $expected === '') {
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
        Cache::put($this->verifiedCacheKey(), true, self::CODE_TTL);

        $this->step = 'reset_password';
    }

    public function resetPassword()
    {
        if (!$this->user || Cache::get($this->verifiedCacheKey()) !== true) {
            $this->step = $this->user ? 'verify_code' : 'identifier';
            $this->addError('verification_code', 'Verify the code we sent you before choosing a new password.');
            return;
        }

        $this->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        Cache::forget($this->verifiedCacheKey());

        $this->user->update([
            'password' => Hash::make($this->password),
            'remember_token' => Str::random(60),
        ]);

        session()->flash('message', 'Password reset successfully! You can now login.');
        return $this->redirect(route('login'));
    }

    private function codeCacheKey(): string
    {
        return 'password_reset_code:' . $this->user->id;
    }

    private function attemptsCacheKey(): string
    {
        return 'password_reset_attempts:' . $this->user->id;
    }

    private function verifiedCacheKey(): string
    {
        return 'password_reset_verified:' . $this->user->id;
    }

    private function sendSMSVerification(string $code)
    {
        try {
            $apiKey = config('services.telnyx.api_key');
            $from = config('services.telnyx.from');
            $messagingProfileId = config('services.telnyx.messaging_profile_id');

            if (! $apiKey || ! $from) {
                throw new \RuntimeException('Telnyx SMS configuration is missing.');
            }

            $to = $this->user?->routeNotificationForTelnyx();

            if (! $to) {
                throw new \RuntimeException('Recipient phone number is missing.');
            }

            // In dev, redirect to dev number
            if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
                $to = $devTo;
            }

            $payload = [
                'from' => $from,
                'to' => $to,
                'text' => $code . ' is your Hive password reset code.',
            ];

            if ($messagingProfileId) {
                $payload['messaging_profile_id'] = $messagingProfileId;
            }

            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/messages', $payload);

            if ($response->failed()) {
                Log::error('Telnyx password reset SMS failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'to' => $to,
                ]);
                throw new \RuntimeException('Telnyx SMS failed: ' . $response->body());
            }
        } catch (\Exception $e) {
            $this->addError('identifier', 'Failed to send SMS. Please try email instead.');
        }
    }

    public function mount()
    {
        $this->can_resend = false;
        $this->resend_countdown = 0;
    }

    #[Title('Account Recovery')]
    public function render()
    {
        return view('livewire.auth.cant-login')->layout('components.layouts.guest');
    }
}
