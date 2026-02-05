<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRegistered
{
    /**
     * Ensure the authenticated user has completed registration.
     *
     * Users who are logged in but haven't completed registration (e.g., passkey
     * creation was successful but the completion callback failed) should be
     * redirected to complete their registration rather than accessing protected routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If not authenticated, let the auth middleware handle it
        if (!$user) {
            return $next($request);
        }

        // Check if user has completed registration
        $isRegistered = $user->registration['registered'] ?? false;

        if (!$isRegistered) {
            // User is logged in but not fully registered
            // Log them out and redirect to registration
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Store notice for registration page
            session()->flash('registration_notice', 'unregistered');

            // Prefill their phone if available
            if ($user->cell_phone) {
                session()->flash('registration_prefill_cell', $user->cell_phone);
            }

            return redirect()->route('registration', ['step' => 'phone']);
        }

        return $next($request);
    }
}
