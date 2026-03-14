<?php

namespace App\Livewire;

use App\Models\Bank;
use App\Models\Check;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Lead;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class AppSidebar extends Component
{
    public function placeholder(): string
    {
        return view('livewire.app-sidebar-placeholder')->render();
    }

    public function render()
    {
        $user = auth()->user();
        $cacheKey = 'sidebar:nav:' . $user->id . ':' . $user->vendor_id;
        $cacheTtl = now()->addMinutes(5);

        $sidebarData = Cache::remember($cacheKey, $cacheTtl, function () use ($user) {
            return $this->buildSidebarData($user);
        });

        // Route-dependent state should not be cached — it changes per request.
        $sidebarData['accountingExpanded'] = request()->is('banks*', 'distributions*', 'sheets*', 'vendors/categories*')
            || request()->routeIs('banks*', 'distributions*', 'sheets*', 'categories*');
        $sidebarData['globalActionsExpanded'] = request()->is('transactions/match_vendor');
        $sidebarData['settingsExpanded'] = request()->is('email_templates*', 'company_emails*', 'vendor_docs*', 'vendor_options*')
            || request()->routeIs('email_templates*', 'company_emails*', 'vendor_docs*', 'vendor_options*');

        return view('livewire.app-sidebar', $sidebarData);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSidebarData(User $user): array
    {
        $isClientUser = $user->is_client_user;
        $isAdmin = $user->vendor_role === 'Admin';

        $hasBankErrors = false;
        $clientHome = null;

        if ($isClientUser) {
            $client = $user->clients()->first();
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
        Cache::forget('sidebar:nav:' . $userId);
    }
}
