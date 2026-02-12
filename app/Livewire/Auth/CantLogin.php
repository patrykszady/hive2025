<?php

namespace App\Livewire\Auth;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

class CantLogin extends Component
{
    public $identifier = '';
    public $step = 'identifier'; // identifier, verify_code, reset_password
    public $verification_code = '';
    public $generated_code = '';
    public $user = null;
    public $password = '';
    public $password_confirmation = '';
    public $resend_countdown = 0;
    public $can_resend = false;

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

        // Generate 6-digit code
        $this->generated_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Send via email or SMS
        if (filter_var($this->identifier, FILTER_VALIDATE_EMAIL)) {
            Mail::to($this->user->email)->send(new EmailVerificationCode($this->generated_code));
        } else {
            $this->sendSMSVerification();
        }

        $this->step = 'verify_code';
        $this->startResendCooldown();
        session()->flash('message', 'Verification code sent!');
    }

    public function resendVerificationCode()
    {
        if (!$this->can_resend) {
            return;
        }

        // Generate new code
        $this->generated_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Send via email or SMS
        if (filter_var($this->identifier, FILTER_VALIDATE_EMAIL)) {
            Mail::to($this->user->email)->send(new EmailVerificationCode($this->generated_code));
        } else {
            $this->sendSMSVerification();
        }

        $this->startResendCooldown();
        session()->flash('message', 'New verification code sent!');
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

        if ($this->verification_code !== $this->generated_code) {
            $this->addError('verification_code', 'Invalid verification code.');
            return;
        }

        $this->step = 'reset_password';
    }

    public function resetPassword()
    {
        $this->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        $this->user->update([
            'password' => Hash::make($this->password),
            'remember_token' => Str::random(60),
        ]);

        session()->flash('message', 'Password reset successfully! You can now login.');
        return $this->redirect(route('login'));
    }

    private function sendSMSVerification()
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

            $payload = [
                'from' => $from,
                'to' => $to,
                'text' => $this->generated_code . ' is your Hive password reset code.',
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