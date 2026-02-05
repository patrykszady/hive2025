<?php

namespace App\Livewire\Entry;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

    public bool $can_confirm_user_cell = false;

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

    public bool $passwords_ready = false;

    public bool $use_password = false;

    public $validate_number = false;

    public $validate_email = false;

    public $step = 'phone';

    public $phone_code_sent_at = null;

    public $email_code_sent_at = null;

    public ?string $confirmed_user_cell = null;

    public bool $show_unregistered_notice = false;

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

    public function passwordsReady(): bool
    {
        return $this->isPasswordsReady();
    }

    protected function isPasswordsReady(): bool
    {
        return $this->password && $this->password_confirmation
            && strlen((string) $this->password) >= 8
            && $this->password === $this->password_confirmation;
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

        $prefillCell = session()->pull('registration_prefill_cell') ?? request()->query('cell');
        $this->show_unregistered_notice = (session()->pull('registration_notice') === 'unregistered')
            || request()->query('notice') === 'unregistered';
        
        // Clear phone state if returning to phone step
        if ($this->step === 'phone') {
            session()->forget('registration_state');
            $this->user_cell = null;
            $this->confirmed_user_cell = null;
            $this->can_confirm_user_cell = false;
            $this->phone_verification = '';
            $this->validate_number = false;
            $this->phone_code_sent_at = null;

            if ($prefillCell) {
                $digits = preg_replace('/\D/', '', (string) $prefillCell);
                if (strlen($digits) === 10) {
                    $this->user_cell = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
                    $this->can_confirm_user_cell = $this->isUserCellValid();
                }
            }
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
        if (in_array($field, ['password', 'password_confirmation'], true)) {
            if (! $this->use_password) {
                return;
            }
            $this->validateOnly('password');
            $this->validateOnly('password_confirmation');
            $this->passwords_ready = $this->isPasswordsReady();
        }

        $this->validateOnly($field);
    }

    public function updatedUserCell(): void
    {
        if ($this->step !== 'phone') {
            return;
        }

        $rawPhone = preg_replace('/[^0-9]/', '', (string) $this->user_cell);

        if ($rawPhone === '') {
            $this->resetErrorBag('user_cell');
            $this->can_confirm_user_cell = false;
            return;
        }

        if (strlen($rawPhone) < 10) {
            $this->addError('user_cell', 'Phone number must be 10 digits.');
            $this->can_confirm_user_cell = false;
            return;
        }

        $this->resetErrorBag('user_cell');
        $this->validateOnly('user_cell');
        $this->can_confirm_user_cell = $this->isUserCellValid();
    }

    protected function isUserCellValid(): bool
    {
        if ($this->step !== 'phone') {
            return false;
        }

        if (! $this->user_cell) {
            return false;
        }

        if (! preg_match('/^\(\d{3}\) \d{3}-\d{4}$/', (string) $this->user_cell)) {
            return false;
        }

        return ! $this->getErrorBag()->has('user_cell');
    }

    public function confirmUserCellAction(): void
    {
        $this->confirmUserCell();
    }

    protected function confirmUserCell(): void
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
            session()->flash('error', [
                'heading' => 'Your number is already registered.',
                'text' => 'Please Login or recover your account instead.',
            ]);
            $this->redirect(route('login'), navigate: true);
            return;
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
                    $this->updateRegistrationStep('phone_code_sent');
                    $this->saveStateToSession();
                    $this->redirect(route('registration', ['step' => 'verify-phone']), navigate: true);
                    return;
                } catch (\Exception $e) {
                    $this->user_cell = null;
                    $this->user = User::make();
                    $this->confirmed_user_cell = null;
                    $this->addError('user_cell', 'Invalid Phone Number.');
                }
            } else {
                //go to email verification (skip cell verification)
                $this->validate_number = false;
                $this->show_email = true;
                $this->saveStateToSession();
                $this->redirect(route('registration', ['step' => 'email']), navigate: true);
                return;
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
        $this->updateRegistrationStep('email_code_sent');
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
        if (! $this->use_password) {
            $this->addError('password', 'Select the password option to set a password.');
            return;
        }

        $this->validate([
            'password' => 'required|min:6',
            'password_confirmation' => 'required|same:password',
        ]);

        if (! isset($this->user->id)) {
            $this->user->cell_phone = $this->user_cell;
            $this->user->email = $this->user->email;
        }

        $this->user->save();

        $this->user->forceFill([
            'password' => Hash::make($this->password),
            'remember_token' => Str::random(60),
        ])->save();

        // Mark registration as complete (clears intermediate steps)
        $this->markAsRegistered();

        Auth::login($this->user);
        
        // Clear session state after successful registration
        session()->forget('registration_state');

        return $this->redirect(route('account_selection'), navigate: true);
    }

    public function prepareUserForPasskey(): bool
    {
        Log::channel('single')->info('prepareUserForPasskey: Starting', [
            'user_id' => $this->user?->id,
            'session_id' => session()->getId(),
        ]);

        $this->validate([
            'user.first_name' => 'required|min:2',
            'user.last_name' => 'required|min:2',
        ]);

        if (! isset($this->user->id)) {
            $this->user->cell_phone = $this->user_cell;
            $this->user->email = $this->user->email;
        }

        $this->user->save();
        Log::channel('single')->info('prepareUserForPasskey: User saved', ['user_id' => $this->user->id]);

        // Log in the user so WebAuthn can register the passkey
        // Note: User is not marked as "registered" until passkey succeeds
        // IMPORTANT: Auth::login() regenerates the session, which changes the session ID.
        // However, since this response hasn't been sent yet, JavaScript will use the
        // old session cookie for WebAuthn requests (causing 403 Forbidden).
        // Solution: Manually set the user in session WITHOUT regenerating the session ID.
        // We'll regenerate the session after successful passkey registration.
        session()->put(Auth::guard()->getName(), $this->user->getAuthIdentifier());
        Auth::setUser($this->user);
        Log::channel('single')->info('prepareUserForPasskey: User logged in (session NOT regenerated)', [
            'user_id' => $this->user->id,
            'session_id' => session()->getId(),
            'auth_check' => Auth::check(),
        ]);
        
        // Save state so we can complete registration
        $this->saveStateToSession();
        Log::channel('single')->info('prepareUserForPasskey: State saved, returning true');
        
        return true;
    }

    public function cancelPasskeyRegistration(): void
    {
        // Log out the user since passkey registration failed/was cancelled
        Auth::logout();
        
        // Keep session state so they can try again
    }

    public function completePasskeyRegistration()
    {
        // Mark registration as complete (clears intermediate steps)
        $this->markAsRegistered();
        
        // Now that passkey is successfully registered, regenerate session for security
        // This prevents session fixation attacks
        session()->regenerate();
        
        session()->forget('registration_state');

        return $this->redirect(route('account_selection'), navigate: true);
    }

    public function register_with_passkey()
    {
        // Deprecated - passkey registration now happens inline
        // Keeping for backwards compatibility
        $this->prepareUserForPasskey();
        $this->markAsRegistered();
        session()->forget('registration_state');

        return $this->redirect(route('passkey.setup'), navigate: true);
    }

    public function showPasswordOption(): void
    {
        $this->use_password = true;
        $this->saveStateToSession();
    }

    public function showPasskeyOption(): void
    {
        $this->use_password = false;
        $this->saveStateToSession();
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

    /**
     * Mark user as fully registered, clearing all intermediate steps.
     */
    protected function markAsRegistered(): void
    {
        // Clear all intermediate steps and just set registered
        $this->user->registration = ['registered' => true];
        
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
            'confirmed_user_cell' => $this->confirmed_user_cell,
            'can_confirm_user_cell' => $this->can_confirm_user_cell,
            'phone_verification' => $this->phone_verification,
            'email_verification' => $this->email_verification,
            'validate_number' => $this->validate_number,
            'validate_email' => $this->validate_email,
            'show_email' => $this->show_email,
            'show_name' => $this->show_name,
            'use_password' => $this->use_password,
            'passwords_ready' => $this->passwords_ready,
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
                    
                    // Check if email is already verified in user's registration state
                    $registration = $existingUser->registration ?? [];
                    if (!empty($registration['email_verified'])) {
                        $this->show_email = true;
                        $this->show_name = true;
                        $this->validate_email = false;
                    }
                } else {
                    // Fallback to creating from array if user not found
                    $this->user = User::make($state['user']);
                }
            } elseif (isset($state['user']) && is_array($state['user'])) {
                $this->user = User::make($state['user']);
            }
            
            $this->user_cell = $state['user_cell'] ?? null;
            $this->confirmed_user_cell = $state['confirmed_user_cell'] ?? null;
            $this->can_confirm_user_cell = $state['can_confirm_user_cell'] ?? $this->isUserCellValid();
            $this->phone_verification = $state['phone_verification'] ?? '';
            $this->email_verification = $state['email_verification'] ?? '';
            $this->validate_number = $state['validate_number'] ?? false;
            $this->validate_email = $state['validate_email'] ?? false;
            $this->show_email = $state['show_email'] ?? false;
            $this->show_name = $state['show_name'] ?? false;
            $this->use_password = $state['use_password'] ?? false;
            $this->passwords_ready = $state['passwords_ready'] ?? $this->isPasswordsReady();
            $this->phone_code_sent_at = $state['phone_code_sent_at'] ?? null;
            $this->email_code_sent_at = $state['email_code_sent_at'] ?? null;
            
            // If user's registration shows email_verified, update local state
            if ($this->user->exists) {
                $registration = $this->user->registration ?? [];
                if (!empty($registration['email_verified'])) {
                    $this->show_name = true;
                }
            }
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
        
        // Check if email is already verified - skip email/verify-email steps
        if ($this->user->exists) {
            $registration = $this->user->registration ?? [];
            if (!empty($registration['email_verified'])) {
                if (in_array($this->step, ['email', 'verify-email'])) {
                    $this->show_name = true;
                    $this->redirect(route('registration', ['step' => 'complete']), navigate: true);
                    return;
                }
            }
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
