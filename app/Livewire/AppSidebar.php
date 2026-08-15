<?php

namespace App\Livewire;

use App\Models\Bank;
use App\Models\Check;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\LienWaiver;
use App\Models\EmailTemplate;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Lead;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class AppSidebar extends Component
{

    public function render()
    {
        $user = auth()->user();
        $cacheKey = 'sidebar:nav:' . $user->id . ':' . ($user->primary_vendor_id ?? 'client');
        $cacheTtl = now()->addMinutes(5);

        $sidebarData = Cache::remember($cacheKey, $cacheTtl, function () use ($user) {
            return $this->buildSidebarData($user);
        });

        // Route-dependent state should not be cached — it changes per request.
        $sidebarData['accountingExpanded'] = request()->is('banks*', 'distributions*', 'sheets*', 'vendors/categories*', 'lien-waivers*')
            || request()->routeIs('banks*', 'distributions*', 'sheets*', 'categories*', 'lien-waivers*');
        $sidebarData['globalActionsExpanded'] = request()->is('transactions/match_vendor', 'agents');
        $sidebarData['settingsExpanded'] = request()->is('email_templates*', 'company_emails*', 'vendor_docs*', 'vendor_options*')
            || request()->routeIs('email_templates*', 'company_emails*', 'vendor_docs*', 'vendor_options*');
        // Always set outside cache to handle stale cached entries missing this key.
        $sidebarData['primaryVendorId'] = $user->primary_vendor_id;

        return view('livewire.app-sidebar', $sidebarData);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSidebarData(User $user): array
    {
        $isClientUser = $user->is_browsing_as_client;
        $isAdmin = $user->vendor_role === 'Admin';

        $hasBankErrors = false;
        $clientHome = null;

        if ($isClientUser) {
            $userVendorIds = $user->vendors()->withoutGlobalScopes()->pluck('vendors.id')->toArray();

            $client = $user->clients()->withoutGlobalScopes()
                ->where(function ($query) use ($userVendorIds) {
                    $query->whereNull('clients.vendor_id')
                        ->orWhereNotIn('clients.vendor_id', $userVendorIds);
                })
                ->first();
            $clientHome = $client ? route('clients.show', $client) : route('account_selection');
        } elseif ($user->can('viewAny', Bank::class) && $user->vendor) {
            $hasBankErrors = $user->vendor->banks()
                ->whereNotNull('plaid_access_token')
                ->get()
                ->where('plaid_options.error', '!=', false)
                ->isNotEmpty();
        }

        return [
            'isClientUser' => $isClientUser,
            'isAdmin' => $isAdmin,
            'hasBankErrors' => $hasBankErrors,
            'clientHome' => $clientHome,
            'canViewBanks' => $user->can('viewAny', Bank::class),
            'canViewLienWaivers' => $user->can('viewAny', LienWaiver::class),
            'canViewLeads' => $user->can('viewAny', Lead::class),
            'canViewExpenses' => $user->can('viewAny', Expense::class) || $user->can('create', Expense::class),
            'canViewPayments' => $user->can('viewAny', Bank::class),
            'canViewChecks' => $user->can('viewAny', Check::class),
            'canViewClients' => $user->can('viewAny', Client::class),
            'canCreateHours' => $user->can('create', Hour::class),
            'canViewTimesheets' => $user->can('viewAny', Timesheet::class),
            'canViewTimesheetPayments' => $user->can('viewAnyPayment', Timesheet::class),
            'canViewOwnTimesheetPayment' => $user->can('viewPayment', [Timesheet::class, $user]),
            'canViewTimesheetsGroup' => $user->can('create', Hour::class)
                || $user->can('viewAny', Timesheet::class)
                || $user->can('viewAnyPayment', Timesheet::class)
                || $user->can('viewPayment', [Timesheet::class, $user]),
            'canAdminLogin' => $user->can('admin_login_as_user', User::class),
            'canViewOptions' => $user->can('viewOptions', Vendor::class),
            'canViewTemplates' => $user->can('viewAny', EmailTemplate::class),
            'canViewCompanyEmails' => $user->can('viewAny', CompanyEmail::class),
            'canViewVendorDocs' => $user->can('viewAny', VendorDoc::class),
            'hasSettingsAccess' => $user->can('viewAny', EmailTemplate::class)
                || $user->can('viewAny', CompanyEmail::class)
                || $user->can('viewAny', VendorDoc::class)
                || $user->can('viewOptions', Vendor::class),
            'userId' => $user->id,
        ];
    }

    /**
     * Bust the sidebar cache for a specific user.
     */
    public static function bustCache(int $userId): void
    {
        // render() keys by user AND primary vendor (see $cacheKey above), so
        // forgetting the bare user key cleared nothing. Clear every vendor
        // variant this user could have, plus the client one.
        $user = \App\Models\User::find($userId);

        $vendorIds = $user
            ? $user->vendors()->pluck('vendors.id')->push($user->primary_vendor_id)
            : collect();

        foreach ($vendorIds->filter()->unique() as $vendorId) {
            Cache::forget('sidebar:nav:' . $userId . ':' . $vendorId);
        }

        Cache::forget('sidebar:nav:' . $userId . ':client');
    }
}
