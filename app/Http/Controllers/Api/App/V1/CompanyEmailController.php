<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\CompanyEmailResource;
use App\Models\CompanyEmail;
use App\Services\CompanyEmails\AppCompanyEmailDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Email Accounts screen — see
 * App\Livewire\CompanyEmails\CompanyEmailsIndex (the web page this
 * mirrors) and App\Services\CompanyEmails\AppCompanyEmailDirectory.
 * Connecting a mailbox (Nylas OAuth popup) stays web-only.
 */
class CompanyEmailController extends Controller
{
    public function __construct(private AppCompanyEmailDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyEmail::class);

        return response()->json([
            'data' => $this->directory->list()->map(fn (CompanyEmail $email) => (new CompanyEmailResource($email))->toArray($request))->values()->all(),
        ]);
    }

    /** Busts the probe cache and re-asks Nylas now — CompanyEmailsIndex::recheck(). */
    public function recheck(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyEmail::class);

        return response()->json([
            'data' => $this->directory->recheck()->map(fn (CompanyEmail $email) => (new CompanyEmailResource($email))->toArray($request))->values()->all(),
        ]);
    }
}
