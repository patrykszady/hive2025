<?php

namespace App\Livewire\Entry;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

//PROGRESSIVE FORM
class Registration extends Component
{
    /** Session keys for the codes sent this session and the phone they proved. */
    private const PHONE_CODE_SESSION_KEY = 'registration_codes.phone';

    private const EMAIL_CODE_SESSION_KEY = 'registration_codes.email';

    private const VERIFIED_CELL_SESSION_KEY = 'registration_verified_cell';

    /** The address the pending email code was sent to. */
    private const PENDING_EMAIL_SESSION_KEY = 'registration_codes.email_for';

    private const VERIFIED_EMAIL_SESSION_KEY = 'registration_verified_email';

    public ?User $user = null;

    /**
     * The typed email and names. They are plain properties rather than
     * `user.*` bindings: with legacy model binding off, Livewire refuses to
     * set model attributes, and an unsaved model reaches the next request as
     * an empty instance, so a new registrant's details would be lost.
     */
    public string $email = '';

    public string $first_name = '';

    public string $last_name = '';

    #[Validate]
    public $user_cell = null;

    public bool $can_confirm_user_cell = false;

    #[Validate]
    public $cell_verification_code = '';

    #[Validate]
    public $email_verification_code = '';

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

    public function maskedEmail(): ?string
    {
        if (! $this->hasExistingEmail()) {
            return null;
        }

        $email = (string) $this->user->email;
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        if ($local === '') {
            return $email;
        }

        $localLength = strlen($local);

        if ($localLength <= 2) {
            $maskedLocal = substr($local, 0, 1) . '*';
        } elseif ($localLength === 3) {
            $maskedLocal = substr($local, 0, 2) . '*';
        } else {
            $maskedLocal = substr($local, 0, 2)
                . str_repeat('*', $localLength - 3)
                . substr($local, -1);
        }

        return $maskedLocal . ($domain ? '@' . $domain : '');
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
            'email' => [
                'required',
                'email',
                'min:6',
                Rule::unique('users', 'email')->ignore($this->user?->id),
            ],
            'first_name' => 'required|min:2',
            'last_name' => 'required|min:2',
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
            $this->forgetRegistrationSession();
            $this->user_cell = null;
            $this->confirmed_user_cell = null;
            $this->can_confirm_user_cell = false;
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

        if ($user_exists && ! empty($user_exists->registration['registered'])) {
            // A registered account is never attached to this component: the
            // remaining steps would otherwise be able to set its password or
            // add a passkey to it.
            $this->user = User::make();
            $this->redirectRegisteredToLogin();
            return;
        }

        if ($user_exists) {
            $this->user = $user_exists;
        } else {
            $this->user->cell_phone = $this->user_cell;
        }

        $code = $this->issueCode(self::PHONE_CODE_SESSION_KEY);

        try {
            $this->sendVerificationSms(
                $this->formatPhoneForSms($this->user?->cell_phone),
                $code . ' is your Hive Contractors text verification code.'
            );

            $this->validate_number = true;
            $this->phone_code_sent_at = now()->timestamp;
            $this->updateRegistrationStep('phone_code_sent');
            $this->saveStateToSession();
            $this->redirect(route('registration', ['step' => 'verify-phone']), navigate: true);
            return;
        } catch (\Exception $e) {
            session()->forget(self::PHONE_CODE_SESSION_KEY);
            $this->user_cell = null;
            $this->user = User::make();
            $this->confirmed_user_cell = null;
            $this->addError('user_cell', 'Invalid Phone Number.');
        }
    }

    public function cell_verification_code_confirm()
    {
        $this->validateOnly('cell_verification_code');

        if (! $this->codeMatches(self::PHONE_CODE_SESSION_KEY, $this->cell_verification_code)) {
            return $this->addError('cell_verification_code', 'Code does not match.');
        }

        session()->forget(self::PHONE_CODE_SESSION_KEY);
        session()->put(self::VERIFIED_CELL_SESSION_KEY, $this->registeringCellDigits());

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

        $code = $this->issueCode(self::PHONE_CODE_SESSION_KEY);

        try {
            $this->sendVerificationSms(
                $this->formatPhoneForSms($this->user?->cell_phone ?? $this->user_cell),
                $code . ' is your Hive Contractors text verification code.'
            );

            $this->phone_code_sent_at = now()->timestamp;
            $this->saveStateToSession();
            session()->flash('success', 'Verification code resent successfully.');
        } catch (\Exception $e) {
            $this->addError('user_cell', 'Failed to resend code. Please try again.');
        }
    }

    private function sendVerificationSms(?string $phone, string $message): void
    {
        $apiKey = config('services.telnyx.api_key');
        $from = config('services.telnyx.from');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (! $phone || ! $apiKey || ! $from) {
            throw new \RuntimeException('Telnyx SMS configuration is missing.');
        }

        // In dev, redirect to dev number
        if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
            $phone = $devTo;
        }

        $payload = [
            'from' => $from,
            'to' => $phone,
            'text' => $message,
        ];

        if ($messagingProfileId) {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages', $payload);

        if ($response->failed()) {
            Log::error('Telnyx SMS verification failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'to' => $phone,
            ]);
            throw new \RuntimeException('Telnyx SMS failed: ' . $response->body());
        }
    }

    private function formatPhoneForSms(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        return str_starts_with($digits, '1') ? '+' . $digits : '+' . $digits;
    }

    public function user_email()
    {
        if ($this->hasExistingEmail()) {
            $address = (string) $this->user->email;
        } else {
            $this->validateOnly('email');
            $address = $this->email;
        }

        $code = $this->issueCode(self::EMAIL_CODE_SESSION_KEY);
        session()->put(self::PENDING_EMAIL_SESSION_KEY, $address);

        Mail::to($address)->send(new EmailVerificationCode($code));

        $this->validate_email = true;
        $this->email_code_sent_at = now()->timestamp;
        $this->updateRegistrationStep('email_code_sent');
        $this->saveStateToSession();
        
        return $this->redirect(route('registration', ['step' => 'verify-email']), navigate: true);
    }

    public function email_verification_code_confirm()
    {
        $this->validateOnly('email_verification_code');

        $verifiedEmail = (string) session(self::PENDING_EMAIL_SESSION_KEY, '');

        if ($verifiedEmail === '' || ! $this->codeMatches(self::EMAIL_CODE_SESSION_KEY, $this->email_verification_code)) {
            return $this->addError('email_verification_code', 'Code does not match.');
        }

        session()->forget([self::EMAIL_CODE_SESSION_KEY, self::PENDING_EMAIL_SESSION_KEY]);
        session()->put(self::VERIFIED_EMAIL_SESSION_KEY, $verifiedEmail);

        if ($this->user->exists && empty($this->user->email)) {
            $this->user->email = $verifiedEmail;
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

        $address = (string) session(self::PENDING_EMAIL_SESSION_KEY, '');

        if ($address === '') {
            $this->addError('email', 'Email address is required.');
            return;
        }

        $code = $this->issueCode(self::EMAIL_CODE_SESSION_KEY);

        // Send code to email
        Mail::to($address)->send(new EmailVerificationCode($code));

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

        if (! $this->canFinishRegistration()) {
            return;
        }

        $this->prefillNamesFromAccount();

        $this->validate([
            'first_name' => 'required|min:2',
            'last_name' => 'required|min:2',
            'password' => 'required|min:6',
            'password_confirmation' => 'required|same:password',
        ]);

        if (! $this->applyRegistrationDetails()) {
            return;
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
        $this->forgetRegistrationSession();

        return $this->redirectIntended(default: route('account_selection'), navigate: true);
    }

    public function prepareUserForPasskey(): bool
    {
        Log::channel('passkey')->info('prepareUserForPasskey: Starting', [
            'user_id' => $this->user?->id,
            'session_id' => session()->getId(),
        ]);

        if (! $this->canFinishRegistration()) {
            Log::channel('passkey')->warning('prepareUserForPasskey: Refused', ['user_id' => $this->user?->id]);

            return false;
        }

        $this->prefillNamesFromAccount();

        $this->validate([
            'first_name' => 'required|min:2',
            'last_name' => 'required|min:2',
        ]);

        if (! $this->applyRegistrationDetails()) {
            return false;
        }

        $this->user->save();
        Log::channel('passkey')->info('prepareUserForPasskey: User saved', ['user_id' => $this->user->id]);

        // Log in the user so WebAuthn can register the passkey
        // Note: User is not marked as "registered" until passkey succeeds
        // IMPORTANT: Auth::login() regenerates the session, which changes the session ID.
        // However, since this response hasn't been sent yet, JavaScript will use the
        // old session cookie for WebAuthn requests (causing 403 Forbidden).
        // Solution: Manually set the user in session WITHOUT regenerating the session ID.
        // We'll regenerate the session after successful passkey registration.
        session()->put(Auth::guard()->getName(), $this->user->getAuthIdentifier());
        Auth::setUser($this->user);
        Log::channel('passkey')->info('prepareUserForPasskey: User logged in (session NOT regenerated)', [
            'user_id' => $this->user->id,
            'session_id' => session()->getId(),
            'auth_check' => Auth::check(),
        ]);
        
        // Save state so we can complete registration
        $this->saveStateToSession();
        Log::channel('passkey')->info('prepareUserForPasskey: State saved, returning true');
        
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
        abort_unless(
            Auth::check() && $this->user->exists && (int) Auth::id() === (int) $this->user->id,
            403
        );

        // Mark registration as complete (clears intermediate steps)
        $this->markAsRegistered();
        
        // Now that passkey is successfully registered, regenerate session for security
        // This prevents session fixation attacks
        session()->regenerate();
        
        $this->forgetRegistrationSession();

        return $this->redirectIntended(default: route('account_selection'), navigate: true);
    }

    public function register_with_passkey()
    {
        // Deprecated - passkey registration now happens inline
        // Keeping for backwards compatibility
        if (! $this->prepareUserForPasskey()) {
            return;
        }

        $this->markAsRegistered();
        $this->forgetRegistrationSession();

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
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'confirmed_user_cell' => $this->confirmed_user_cell,
            'can_confirm_user_cell' => $this->can_confirm_user_cell,
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
            $this->email = (string) ($state['email'] ?? '');
            $this->first_name = (string) ($state['first_name'] ?? '');
            $this->last_name = (string) ($state['last_name'] ?? '');
            $this->confirmed_user_cell = $state['confirmed_user_cell'] ?? null;
            $this->can_confirm_user_cell = $state['can_confirm_user_cell'] ?? $this->isUserCellValid();
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

    /**
     * A fresh six-digit code, kept in the session under $sessionKey so the
     * browser never sees it.
     */
    private function issueCode(string $sessionKey): string
    {
        $code = (string) random_int(100000, 999999);
        session()->put($sessionKey, $code);

        return $code;
    }

    private function codeMatches(string $sessionKey, mixed $typed): bool
    {
        $expected = (string) session($sessionKey, '');

        return $expected !== '' && hash_equals($expected, (string) $typed);
    }

    /**
     * Digits of the phone this registration is for: the account's number for
     * an existing account, otherwise the number typed on the first step.
     */
    private function registeringCellDigits(): string
    {
        $cell = $this->user?->exists ? $this->user->cell_phone : $this->user_cell;

        return preg_replace('/\D/', '', (string) $cell);
    }

    /**
     * Registration may only finish for an account that is not registered yet
     * and whose phone was verified by code in this session. Without this,
     * anyone who typed someone else's phone could set that account's password
     * or attach a passkey to it.
     */
    private function canFinishRegistration(): bool
    {
        if ($this->user->exists && ! empty($this->user->registration['registered'])) {
            $this->redirectRegisteredToLogin();

            return false;
        }

        $cell = $this->registeringCellDigits();

        if ($cell === '' || session(self::VERIFIED_CELL_SESSION_KEY) !== $cell) {
            $this->addError('user_cell', 'Verify your phone number first.');
            $this->redirect(route('registration', ['step' => 'phone']), navigate: true);

            return false;
        }

        return true;
    }

    /**
     * An existing account's names count as typed when the form shows them as
     * read-only, so validation passes without retyping them.
     */
    private function prefillNamesFromAccount(): void
    {
        if (! $this->user->exists) {
            return;
        }

        if ($this->first_name === '') {
            $this->first_name = (string) $this->user->first_name;
        }

        if ($this->last_name === '') {
            $this->last_name = (string) $this->user->last_name;
        }
    }

    /**
     * Copies the verified phone and email and the typed names onto the
     * account about to be saved. A new account needs an email verified by
     * code in this session.
     */
    private function applyRegistrationDetails(): bool
    {
        if (! $this->user->exists) {
            $verifiedEmail = (string) session(self::VERIFIED_EMAIL_SESSION_KEY, '');

            if ($verifiedEmail === '') {
                $this->addError('email', 'Verify your email address first.');
                $this->redirect(route('registration', ['step' => 'email']), navigate: true);

                return false;
            }

            $this->user->cell_phone = $this->user_cell;
            $this->user->email = $verifiedEmail;
        }

        $this->user->first_name = $this->first_name;
        $this->user->last_name = $this->last_name;

        return true;
    }

    private function redirectRegisteredToLogin(): void
    {
        session()->flash('error', [
            'heading' => 'Your number is already registered.',
            'text' => 'Please Login or recover your account instead.',
        ]);
        $this->redirect(route('login'), navigate: true);
    }

    private function forgetRegistrationSession(): void
    {
        session()->forget([
            'registration_state',
            'registration_codes',
            self::VERIFIED_CELL_SESSION_KEY,
            self::VERIFIED_EMAIL_SESSION_KEY,
        ]);
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
        
        if ($this->step === 'verify-phone' && !$this->validate_number && ! session()->has(self::PHONE_CODE_SESSION_KEY)) {
            $redirectTo = 'phone';
        } elseif ($this->step === 'email' && !$this->show_email) {
            $redirectTo = 'phone';
        } elseif ($this->step === 'verify-email' && !$this->validate_email && ! session()->has(self::EMAIL_CODE_SESSION_KEY)) {
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
