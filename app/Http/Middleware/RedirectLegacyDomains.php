<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyDomains
{
    /**
     * Legacy domains that should redirect to the main domain.
     */
    protected array $legacyDomains = [
        'dashboard.hive.contractors',
        'hub.hive.contractors',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getHost(), $this->legacyDomains, true)) {
            return redirect()->to(
                config('app.url') . $request->getRequestUri(),
                301
            );
        }

        return $next($request);
    }
}
