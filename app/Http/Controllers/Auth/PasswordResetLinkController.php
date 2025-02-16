<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.forgot-password');
    }

    // @section('title', 'Hive Contractors | Forgot Password')
    /**
     * Handle an incoming password reset link request.
     *
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        //if email is not registered
        $user = User::where('email', $request->email)->first();
        if (isset($user)) {
            if (isset($user->registration)) {
                if ($user->registration['registered'] == true) {
                    // We will send the password reset link to this user. Once we have attempted
                    // to send the link, we will examine the response then see the message we
                    // need to show to the user. Finally, we'll send out a proper response.
                    $status = Password::sendResetLink(
                        $request->only('email')
                    );

                    return $status == Password::RESET_LINK_SENT
                                ? back()->with('status', __($status))
                                : back()->withInput($request->only('email'))
                                    ->withErrors(['email' => __($status)]);
                }
            }
        } else {
            //send error and prompt to register
            //validatin error in bag...

            return back()->withErrors(['email_not_registered' => 'This email is not registered.']);
        }
    }
}
