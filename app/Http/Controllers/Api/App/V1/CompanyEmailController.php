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

    /**
     * Mirrors the web page's own first paint exactly: CompanyEmailsIndex
     * renders from cache immediately, then `wire:init="probeHealth"` fires
     * right after — but probeHealth() re-asks Nylas only when
     * AppCompanyEmailDirectory::health()'s 5-minute cache has gone stale,
     * so calling probe() here (rather than the cache-only list()) doesn't
     * make this request any slower on a normal repeat visit, and it means
     * the app never gets stuck showing "Checking…" the way a cache-only
     * read would on a cold cache.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyEmail::class);

        return response()->json([
            'data' => $this->directory->probe()->map(fn (CompanyEmail $email) => (new CompanyEmailResource($email))->toArray($request))->values()->all(),
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
