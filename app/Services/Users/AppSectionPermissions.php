<?php

namespace App\Services\Users;

use App\Models\Bank;
use App\Models\Check;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Lead;
use App\Models\LineItem;
use App\Models\LienWaiver;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;

/**
 * Which sections of Hive the signed-in person may open, for the app's
 * More tab — the same checks App\Livewire\AppSidebar runs for the web
 * sidebar, so the app never shows a section the web would hide. Each value
 * is only a visibility hint: every app endpoint still authorizes itself.
 */
class AppSectionPermissions
{
    /**
     * @return array<string, bool>
     */
    public static function for(User $user): array
    {
        $vendorId = $user->vendor?->id;

        return [
            'expenses' => $user->can('viewAny', Expense::class) || $user->can('create', Expense::class),
            'expenses_create' => $user->can('create', Expense::class),
            'payments' => $user->can('viewAny', Payment::class),
            'payments_create' => $user->can('create', Payment::class),
            'checks' => $user->can('viewAny', Check::class),
            'clients' => $user->can('viewAny', Client::class),
            'clients_create' => $user->can('create', Client::class),
            // Everyone on the team sees the team, as on the web's vendor page
            // (UserPolicy::viewAny is an unimplemented stub, so it is not asked).
            'team' => true,
            'team_create' => $vendorId !== null && $user->can('create_team_member', [User::class, $vendorId]),
            // The web sidebar shows Vendors to everyone, unconditionally
            // (VendorPolicy::viewAny is an unimplemented stub, so it is not asked).
            'vendors' => true,
            'hours' => $user->can('create', Hour::class),
            'timesheets' => $user->can('viewAny', Timesheet::class),
            'timesheet_payments' => $user->can('viewAnyPayment', Timesheet::class),
            'own_balance' => $user->can('viewPayment', [Timesheet::class, $user]),
            'estimates' => $user->can('viewAny', Estimate::class),
            'leads' => $user->can('viewAny', Lead::class),
            'lien_waivers' => $user->can('viewAny', LienWaiver::class),
            'banks' => $user->can('viewAny', Bank::class),
            'phone_system' => (int) $user->primary_vendor_id === 1 && $user->can('viewOptions', Vendor::class),
            'projects_create' => $user->can('create', Project::class),
            'tasks_create' => $user->can('create', Task::class),
            'line_items' => $user->can('viewAny', LineItem::class),
            // The sidebar's Banks group: Distributions, Sheets and Categories
            // all sit under the one canViewBanks gate there.
            'distributions' => $user->can('viewAny', Bank::class),
            'sheets' => $user->can('viewAny', Bank::class),
            'categories' => $user->can('viewAny', Bank::class),
            'options' => $user->can('viewOptions', Vendor::class),
            'templates' => $user->can('viewAny', EmailTemplate::class),
            'company_emails' => $user->can('viewAny', CompanyEmail::class),
            // The sidebar shows Vendor Match under the same gate as Email Accounts.
            'vendor_match' => $user->can('viewAny', CompanyEmail::class),
            'vendor_docs' => $user->can('viewAny', VendorDoc::class),
            // The unplaced email inbox: Admins, the same gate as Email Accounts.
            'unplaced_emails' => $user->can('viewAny', CompanyEmail::class),
            'agents' => $user->id === 1,
        ];
    }
}
