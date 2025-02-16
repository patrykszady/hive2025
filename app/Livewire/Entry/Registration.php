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
    public User $user;

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

    public function rules()
    {
        return [
            'user_cell' => 'required|digits:10',
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
        $this->user = User::make();
    }

    protected $messages =
        [
            'user_cell.required' => 'Phone numberis required.',
            'user_cell.digits' => 'Phone number must be 10 digits.',
        ];

    public function updated($field)
    {
        if (in_array($field, ['password', 'password_confirmation'])) {
            $this->validateOnly('password');
            $this->validateOnly('password_confirmation');
        }

        $this->validateOnly($field);
    }

    public function user_cell_confirm()
    {
        $this->validateOnly('user_cell');
        $user_exists = User::where('cell_phone', $this->user_cell)->first();

        if ($user_exists) {
            $this->user = $user_exists;
        } else {
            $this->user->cell_phone = $this->user_cell;
        }

        if (isset($this->user->registration['registered'])) {
            //2-9-2023 Why doesnt this get passed to redirect?
            // $this->dispatchBrowserEvent('notify', [
            //     'type' => 'success',
            //     'content' => 'User Already Registered'
            // ]);
            session()->flash('error', 'Your number is already registered. Please Login or recover your account instead.');

            return redirect(route('login'));
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
                        // the number you'd like to send the message to
                        $this->user->cell_phone,
                        [
                            'from' => env('TWILIO_FROM'),
                            'body' => $this->phone_verification.' is your Hive Contractors text verification code.',
                        ]
                    );

                    $this->validate_number = true;
                } catch (\Exception $e) {
                    $this->user_cell = null;
                    $this->user = User::make();
                    $this->addError('user_cell', 'Invalid Phone Number.');
                }

                // $this->user->registration['cell_verified'] =
            } else {
                //go to email_verification_code (skip cell_verification_code);
                $this->validate_number = false;
                $this->show_email = true;
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

        //User is cell_verified = TRUE
        // if(isset($this->user->id)){
        //     $array = $this->user->registration == NULL ? array() : $this->user->registration;
        //     $this->user->registration = json_encode(array_merge($array, ["cell_verified" => TRUE]));
        //     $this->user->update();
        // }

        //next Validate email (same as cell verification)
        $this->show_email = true;
    }

    public function user_email()
    {
        $this->validateOnly('user.email');
        // $this->user->save();
        $this->email_verification = mt_rand(100000, 999999);

        //2-8-2023 QUERY THIS!
        //send code to email
        Mail::to($this->user->email)->send(new EmailVerificationCode($this->email_verification));

        $this->validate_email = true;
        // if(!isset($this->user->registration['email_verified'])){
        //     //generate random 6 digit code
        //     $this->email_verification = mt_rand(100000, 999999);

        //     //2-8-2023 QUERY THIS!
        //     //send code to email
        //     Mail::to($this->user->email)->send(new EmailVerificationCode($this->email_verification));

        //     $this->validate_email = TRUE;
        // }else{
        //     //go to email_verification_code (skip email_verification_code);
        //     $this->validate_email = FALSE;
        //     $this->show_name = TRUE;
        // }
    }

    public function email_verification_code_confirm()
    {
        $this->validateOnly('email_verification_code');

        //validate code with $this->user->phone_verification
        if ($this->email_verification_code != $this->email_verification) {
            return $this->addError('email_verification_code', 'Code does not match.');
        }

        //if matches $user->registration->phone = TRUE
        $this->validate_email = false;

        //User is cell_verified = TRUE
        // if(isset($this->user->id)){
        //     $array = $this->user->registration == NULL ? array() : $this->user->registration;
        //     $this->user->registration = json_encode(array_merge($array, ["email_verified" => TRUE]));
        //     $this->user->update();
        // }

        $this->show_name = true;
    }

    public function register_user()
    {
        $array = $this->user->registration == null ? [] : $this->user->registration;
        $this->user->registration = json_encode(array_merge($array, ['registered' => true]));

        if (! isset($this->user->id)) {
            $this->user->cell_phone = $this->user_cell;
            $this->user->email = $this->user->email;
        }

        $this->user->save();

        $this->user->forceFill([
            'password' => Hash::make($this->password),
            'remember_token' => Str::random(60),
        ])->save();

        Auth::login($this->user);

        return redirect(route('vendor_selection'));
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
