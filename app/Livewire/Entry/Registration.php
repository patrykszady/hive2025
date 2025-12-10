<?php

namespace App\Livewire\Entry;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Twilio\Rest\Client;

//PROGRESSIVE FORM
class Registration extends Component
{
    public ?User $user = null;

    #[Validate]
    public $user_cell = null;

    #[Validate]
    public $cell_verification_code = '';

    public $phone_verification = '';

    #[Validate]
    public $email_verification_code = '';

    public $email_verification = '';

    public $show_email = false;

    public $show_name = false;

    public $password = null;

    public $password_confirmation = null;

    public $validate_number = false;

    public $validate_email = false;

    public $step = 'phone';

    public $phone_code_sent_at = null;

    public $email_code_sent_at = null;

    public $user_cell_valid = false;

    public function canResendPhone(): bool
    {
        if (!$this->phone_code_sent_at) {
            return true;
        }
        
        return (now()->timestamp - $this->phone_code_sent_at) >= 60;
    }

    public function canResendEmail(): bool
    {
        if (!$this->email_code_sent_at) {
            return true;
        }
        
        return (now()->timestamp - $this->email_code_sent_at) >= 60;
    }

    public function phoneResendCountdown(): int
    {
        if (!$this->phone_code_sent_at) {
            return 0;
        }
        
        $remaining = 60 - (now()->timestamp - $this->phone_code_sent_at);
        return max(0, $remaining);
    }

    public function emailResendCountdown(): int
    {
        if (!$this->email_code_sent_at) {
            return 0;
        }
        
        $remaining = 60 - (now()->timestamp - $this->email_code_sent_at);
        return max(0, $remaining);
    }

    public function hasExistingEmail(): bool
    {
        return $this->user && $this->user->exists && !empty($this->user->email);
    }

    public function rules()
    {
        return [
            'user_cell' => 'required|regex:/^\(\d{3}\) \d{3}-\d{4}$/',
            'cell_verification_code' => 'required|digits:6',
            'email_verification_code' => 'required|digits:6',
            'password' => 'required|min:6',
            'password_confirmation' => 'required|same:password',
            // 'user.cell_phone' => [
            //     'required',
            //     'digits:10',
            //     Rule::unique('users', 'cell_phone')->ignore($this->user->id),
            // ],
            'user.email' => [
                'required',
                'email',
                'min:6',
                Rule::unique('users', 'email')->ignore($this->user->id),
            ],
            'user.first_name' => 'required|min:2',
            'user.last_name' => 'required|min:2',
        ];
    }

    public function mount()
    {
        // Initialize user first
        $this->user = User::make();
        
        // Get step from query parameter or default to 'phone'
        $this->step = request()->query('step', 'phone');
        
        // Clear phone state if returning to phone step
        if ($this->step === 'phone') {
            session()->forget('registration_state');
            $this->user_cell = null;
            $this->user_cell_valid = false;
            $this->phone_verification = '';
            $this->validate_number = false;
            $this->phone_code_sent_at = null;
        } else {
            // Load persisted state from session for other steps
            $this->loadStateFromSession();
        }
        
        // Validate step progression and redirect if needed
        $this->validateStepAccess();
    }

    protected $messages =
        [
            'user_cell.required' => 'Phone number is required.',
            'user_cell.regex' => 'Phone number must be in format (555) 555-5555.',
        ];

    public function updated($field)
    {
        if (in_array($field, ['password', 'password_confirmation'])) {
            $this->validateOnly('password');
            $this->validateOnly('password_confirmation');
        }

        if ($field === 'user_cell') {
            // Only validate if user has typed enough characters (at least 10 digits)
            $rawPhone = preg_replace('/[^0-9]/', '', $this->user_cell);
            
            // If they've typed something substantial but incomplete, show error
            if (!empty($rawPhone) && strlen($rawPhone) < 10) {
                $this->user_cell_valid = false;
                $this->addError('user_cell', 'Phone number must be 10 digits.');
            } elseif (strlen($rawPhone) >= 10) {
                // Full number entered, validate format
                try {
                    $this->validateOnly('user_cell');
                    $this->user_cell_valid = true;
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $this->user_cell_valid = false;
                    throw $e;
                }
            } else {
                // Empty or just started typing, clear errors
                $this->resetErrorBag('user_cell');
                $this->user_cell_valid = false;
            }
        } else {
            $this->validateOnly($field);
        }
    }

    public function user_cell_confirm()
    {
        $this->validateOnly('user_cell');
        
        // Strip formatting to get raw 10-digit number
        $rawPhone = preg_replace('/[^0-9]/', '', $this->user_cell);
        
        $user_exists = User::where('cell_phone', $this->user_cell)
            ->orWhere('cell_phone', $rawPhone)
            ->first();

        if ($user_exists) {
            $this->user = $user_exists;
        } else {
            $this->user->cell_phone = $this->user_cell;
        }

        if (isset($this->user->registration['registered'])) {
            session()->flash('error', 'Your number is already registered. Please Login or recover your account instead.');
            return $this->redirect(route('login'), navigate: true);
        } else {
            if (! isset($this->user->registration['cell_verified'])) {
                //generate random 6 digit code
                $this->phone_verification = mt_rand(100000, 999999);

                //send Twillo verification code
                $sid = env('TWILIO_SID');
                $token = env('TWILIO_TOKEN');
                $twilio = new Client($sid, $token);

                try {
                    $twilio->messages->create(
                        $this->user->cell_phone,
                        [
                            'from' => env('TWILIO_FROM'),
                            'body' => $this->phone_verification.' is your Hive Contractors text verification code.',
                        ]
                    );

                    $this->validate_number = true;
                    $this->phone_code_sent_at = now()->timestamp;
                    $this->updateRegistrationStep('phone_confirmed');
                    $this->saveStateToSession();
                    return $this->redirect(route('registration', ['step' => 'verify-phone']), navigate: true);
                } catch (\Exception $e) {
                    $this->user_cell = null;
                    $this->user = User::make();
                    $this->addError('user_cell', 'Invalid Phone Number.');
                }
            } else {
                //go to email verification (skip cell verification)
                $this->validate_number = false;
                $this->show_email = true;
                $this->saveStateToSession();
                return $this->redirect(route('registration', ['step' => 'email']), navigate: true);
            }
        }
    }

    public function cell_verification_code_confirm()
    {
        $this->validateOnly('cell_verification_code');

        //validate code with $this->user->phone_verification
        if ($this->cell_verification_code != $this->phone_verification) {
            return $this->addError('cell_verification_code', 'Code does not match.');
        }

        $this->validate_number = false;
        $this->show_email = true;
        $this->updateRegistrationStep('cell_verified');
        $this->saveStateToSession();
        
        return $this->redirect(route('registration', ['step' => 'email']), navigate: true);
    }

    public function resendPhoneCode()
    {
        // Check if 60 seconds have passed since last send
        if ($this->phone_code_sent_at && (now()->timestamp - $this->phone_code_sent_at) < 60) {
            $remaining = 60 - (now()->timestamp - $this->phone_code_sent_at);
            $this->addError('phone_resend', "Please wait {$remaining} seconds before resending.");
            return;
        }

        if (!$this->user_cell) {
            $this->addError('user_cell', 'Phone number is required.');
            return;
        }

        // Generate new 6 digit code
        $this->phone_verification = mt_rand(100000, 999999);

        // Send Twilio verification code
        $sid = env('TWILIO_SID');
        $token = env('TWILIO_TOKEN');
        $twilio = new Client($sid, $token);

        try {
            $twilio->messages->create(
                $this->user->cell_phone ?? $this->user_cell,
                [
                    'from' => env('TWILIO_FROM'),
                    'body' => $this->phone_verification.' is your Hive Contractors text verification code.',
                ]
            );

            $this->phone_code_sent_at = now()->timestamp;
            $this->saveStateToSession();
            session()->flash('success', 'Verification code resent successfully.');
        } catch (\Exception $e) {
            $this->addError('user_cell', 'Failed to resend code. Please try again.');
        }
    }

    public function user_email()
    {
        $this->validateOnly('user.email');
        $this->email_verification = mt_rand(100000, 999999);

        //send code to email
        Mail::to($this->user->email)->send(new EmailVerificationCode($this->email_verification));

        $this->validate_email = true;
        $this->email_code_sent_at = now()->timestamp;
        $this->updateRegistrationStep('email_confirmed');
        $this->saveStateToSession();
        
        return $this->redirect(route('registration', ['step' => 'verify-email']), navigate: true);
    }

    public function email_verification_code_confirm()
    {
        $this->validateOnly('email_verification_code');

        //validate code with $this->user->phone_verification
        if ($this->email_verification_code != $this->email_verification) {
            return $this->addError('email_verification_code', 'Code does not match.');
        }

        $this->validate_email = false;
        $this->show_name = true;
        $this->updateRegistrationStep('email_verified');
        $this->saveStateToSession();
        
        return $this->redirect(route('registration', ['step' => 'complete']), navigate: true);
    }

    public function resendEmailCode()
    {
        // Check if 60 seconds have passed since last send
        if ($this->email_code_sent_at && (now()->timestamp - $this->email_code_sent_at) < 60) {
            $remaining = 60 - (now()->timestamp - $this->email_code_sent_at);
            $this->addError('email_resend', "Please wait {$remaining} seconds before resending.");
            return;
        }

        if (!$this->user->email) {
            $this->addError('user.email', 'Email address is required.');
            return;
        }

        // Generate new 6 digit code
        $this->email_verification = mt_rand(100000, 999999);

        // Send code to email
        Mail::to($this->user->email)->send(new EmailVerificationCode($this->email_verification));

        $this->email_code_sent_at = now()->timestamp;
        $this->saveStateToSession();
        session()->flash('success', 'Verification code resent successfully.');
    }

    public function register_user()
    {
        if (! isset($this->user->id)) {
            $this->user->cell_phone = $this->user_cell;
            $this->user->email = $this->user->email;
        }

        $this->user->save();

        $this->user->forceFill([
            'password' => Hash::make($this->password),
            'remember_token' => Str::random(60),
        ])->save();

        // Mark registration as complete
        $this->updateRegistrationStep('registered', true);

        Auth::login($this->user);
        
        // Clear session state after successful registration
        session()->forget('registration_state');

        return $this->redirect(route('vendor_selection'), navigate: true);
    }

    protected function updateRegistrationStep(string $step, bool $value = true): void
    {
        $registration = $this->user->registration ?? [];
        $registration[$step] = $value;
        $registration['last_step'] = $step;
        $registration['updated_at'] = now()->toDateTimeString();
        
        $this->user->registration = $registration;
        
        // Save to database if user exists
        if ($this->user->exists) {
            $this->user->save();
        }
    }

    protected function saveStateToSession(): void
    {
        $userData = $this->user->toArray();
        // Ensure we capture the user ID if it exists
        if ($this->user->exists) {
            $userData['id'] = $this->user->id;
        }
        
        session(['registration_state' => [
            'user' => $userData,
            'user_id' => $this->user->id ?? null,
            'user_cell' => $this->user_cell,
            'user_cell_valid' => $this->user_cell_valid,
            'phone_verification' => $this->phone_verification,
            'email_verification' => $this->email_verification,
            'validate_number' => $this->validate_number,
            'validate_email' => $this->validate_email,
            'show_email' => $this->show_email,
            'show_name' => $this->show_name,
            'phone_code_sent_at' => $this->phone_code_sent_at,
            'email_code_sent_at' => $this->email_code_sent_at,
        ]]);
    }

    protected function loadStateFromSession(): void
    {
        $state = session('registration_state');
        
        if ($state) {
            // If we have a user ID, load the existing user from database
            if (isset($state['user_id']) && $state['user_id']) {
                $existingUser = User::find($state['user_id']);
                if ($existingUser) {
                    $this->user = $existingUser;
                } else {
                    // Fallback to creating from array if user not found
                    $this->user = User::make($state['user']);
                }
            } elseif (isset($state['user']) && is_array($state['user'])) {
                $this->user = User::make($state['user']);
            }
            
            $this->user_cell = $state['user_cell'] ?? null;
            $this->user_cell_valid = $state['user_cell_valid'] ?? false;
            $this->phone_verification = $state['phone_verification'] ?? '';
            $this->email_verification = $state['email_verification'] ?? '';
            $this->validate_number = $state['validate_number'] ?? false;
            $this->validate_email = $state['validate_email'] ?? false;
            $this->show_email = $state['show_email'] ?? false;
            $this->show_name = $state['show_name'] ?? false;
            $this->phone_code_sent_at = $state['phone_code_sent_at'] ?? null;
            $this->email_code_sent_at = $state['email_code_sent_at'] ?? null;
        }
    }

    protected function validateStepAccess(): void
    {
        $allowedSteps = ['phone', 'verify-phone', 'email', 'verify-email', 'complete'];
        
        // If invalid step, redirect to phone
        if (!in_array($this->step, $allowedSteps)) {
            $this->redirect(route('registration', ['step' => 'phone']), navigate: true);
            return;
        }
        
        // Step progression rules
        $redirectTo = null;
        
        if ($this->step === 'verify-phone' && !$this->validate_number && empty($this->phone_verification)) {
            $redirectTo = 'phone';
        } elseif ($this->step === 'email' && !$this->show_email) {
            $redirectTo = 'phone';
        } elseif ($this->step === 'verify-email' && !$this->validate_email && empty($this->email_verification)) {
            $redirectTo = 'email';
        } elseif ($this->step === 'complete' && !$this->show_name) {
            $redirectTo = 'phone';
        }
        
        if ($redirectTo) {
            $this->redirect(route('registration', ['step' => $redirectTo]), navigate: true);
        }
    }

    #[Title('Registration')]
    #[Layout('components.layouts.guest')]
    public function render()
    {
        // NOT READY FOR REGISTRATION YET
        // return view('livewire.entry.registration-not-ready');
        return view('livewire.entry.registration');
    }
}
