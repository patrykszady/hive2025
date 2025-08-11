<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VendorAccessControl
{
    /**
     * Handle vendor access logic throughout the application
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        $routeName = $request->route()->getName();
        
        // Special handling for vendor registration route
        if ($routeName === 'vendor_registration') {
            $vendorParam = $request->route('vendor');
            
            // Can only access if this is user's vendor and not registered
            if (!$user->vendor || 
                $vendorParam->id != $user->vendor->id || 
                $this->isRegistrationComplete($user->vendor)) {
                return redirect(route('vendor_selection'));
            }
            
            // Allow access to registration page
            return $next($request);
        }
        
        // Handle regular routes
        if (!$user->vendor->id) {
            // No primary vendor selected
            return redirect(route('vendor_selection'));
        }
        
        // User has primary vendor, check if registered
        if (!$this->isRegistrationComplete($user->vendor)) {
            // Vendor exists but not registered
            return redirect(route('vendor_registration', $user->vendor->id));
        }
        
        // All checks passed - allow access
        return $next($request);
    }

    /**
     * Check if vendor registration is complete
     */
    protected function isRegistrationComplete($vendor): bool
    {
        // No vendor or no registration
        if (!$vendor?->registration) {
            return false;
        }
        
        // If registered is explicitly set to false, registration is not complete
        if (isset($vendor->registration->registered) && $vendor->registration->registered === false) {
            return false;
        }
        
        // If the registered flag is explicitly true, consider registration complete
        if (isset($vendor->registration->registered) && $vendor->registration->registered === true) {
            return true;
        }
        
        // For vendors without the explicit registered flag, check all steps
        $requiredSteps = ['vendor_info', 'team_members'];
        
        // Additional steps for Sub/DBA vendors
        if (in_array($vendor->business_type, ['Sub', 'DBA'])) {
            $requiredSteps = array_merge($requiredSteps, ['emails_registered', 'banks_registered']);
        }
        
        // Check all required steps
        foreach ($requiredSteps as $step) {
            if (!isset($vendor->registration->{$step}) || !$vendor->registration->{$step}) {
                return false;
            }
        }
        
        return true;
    }
}