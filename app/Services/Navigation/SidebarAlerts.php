<?php

namespace App\Services\Navigation;

use App\Http\Controllers\MenardsSyncStatusController;
use App\Models\Bank;
use App\Models\CompanyEmail;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Support\Facades\Cache;

/**
 * The alert cluster at the top of the web sidebar (App\Livewire\AppSidebar):
 * a bank whose Plaid link errored, a Menards receipt browser waiting for a
 * human sign-in, and stakeholder emails the AI couldn't put on a project.
 * The app's Menu reads the same answers through MenuAlertsController.
 */
class SidebarAlerts
{
    /** Any of the person's vendor's Plaid-linked banks reporting an error. */
    public function hasBankErrors(User $user): bool
    {
        if ($user->is_browsing_as_client || ! $user->vendor || ! $user->can('viewAny', Bank::class)) {
            return false;
        }

        return $user->vendor->banks()
            ->whereNotNull('plaid_access_token')
            ->get()
            ->where('plaid_options.error', '!=', false)
            ->isNotEmpty();
    }

    /**
     * Menards receipt browser needs a human: the extension reported a dead
     * session, or an automated sign-in failed (e.g. the Imperva challenge).
     */
    public function menardsNeedsLogin(User $user): bool
    {
        if (! $user->can('menards-browser')) {
            return false;
        }

        $syncStatus = Cache::get(MenardsSyncStatusController::CACHE_KEY);

        return (bool) ($syncStatus['session_expired'] ?? false)
            || Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY);
    }

    /** Stakeholder emails waiting to be put on a project. */
    public function unplacedEmailCount(User $user): int
    {
        if (! $user->vendor || ! $user->can('viewAny', CompanyEmail::class)) {
            return 0;
        }

        return EmailMessage::query()->where('belongs_to_vendor_id', $user->vendor->id)->unplaced()->count();
    }
}
