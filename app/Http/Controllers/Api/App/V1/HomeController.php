<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\TeamUserResource;
use App\Http\Resources\Api\App\VendorResource;
use App\Models\User;
use App\Services\Dashboard\AppHomeAgenda;
use App\Services\Dashboard\MonthlyFinancialSummary;
use App\Services\Tasks\TaskChecklistToggle;
use App\Services\Users\AppTeamDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Home tab — the same cards the web Hub (App\Livewire\Dashboard\
 * DashboardShow) shows a signed-in GS Construction team member: the task
 * schedule (App\Services\Dashboard\AppHomeAgenda, mirroring VendorTasks),
 * a vendor snapshot (reusing VendorResource, same as the Vendors tab) and a
 * team snapshot (reusing TeamUserResource and AppTeamDirectory, same as the
 * Team tab), plus the admin-only monthly payments/expenses summary
 * (App\Services\Dashboard\MonthlyFinancialSummary, mirroring SheetMonthly).
 */
class HomeController extends Controller
{
    /** The Hub shows at most this many team members before "see all" takes over on the Team tab. */
    private const TEAM_PREVIEW_LIMIT = 6;

    public function show(Request $request, AppHomeAgenda $agenda, AppTeamDirectory $team, MonthlyFinancialSummary $monthlySummary): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $vendor = $user->vendor;

        // EnsureAppUserAllowed already guarantees primary_vendor_id === 1
        // resolves to a real Vendor, but a deleted/misconfigured row is
        // cheaper to 404 than to let every query below silently return
        // nothing.
        abort_unless($vendor, 404);

        $data = $agenda->build($user, $vendor);

        $canManageVendor = $user->can('update', $vendor);
        $members = $team->teamMembers($vendor);

        $data['vendor'] = (new VendorResource($vendor))->canManage($canManageVendor)->toArray($request);
        $data['team'] = [
            'count' => $members->count(),
            'members' => $members
                ->take(self::TEAM_PREVIEW_LIMIT)
                ->map(fn (User $member) => (new TeamUserResource($member))->toArray($request))
                ->values()
                ->all(),
        ];

        // SheetMonthly's card is wrapped in @can('hasAdminRole', $user) on
        // the web — the query never even runs for anyone else.
        $data['monthly'] = $user->can('hasAdminRole', $user)
            ? $monthlySummary->months($agenda->today())
            : null;

        return response()->json(['data' => $data]);
    }

    /**
     * Ticks (or unticks) one checklist item on a task shown in the Home
     * tab's schedule — same rule as the web Hub's cards
     * (App\Livewire\Concerns\TogglesTaskChecklist, via the shared
     * App\Services\Tasks\TaskChecklistToggle): team members only, own
     * vendor's tasks only, and only from the task's scheduled day onward.
     * Unlike the Livewire trait (a fire-and-forget event), the app needs a
     * real response to tell a refused tap from a successful one.
     */
    public function toggleChecklistItem(Request $request, int $task, int $index, AppHomeAgenda $agenda, TaskChecklistToggle $toggle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $toggle->toggle($user, $task, $index, $agenda->today());

        abort_if($updated === null, 404);

        return response()->json(['data' => [
            'id' => $updated->id,
            'checklist' => $agenda->checklistPayload($updated),
        ]]);
    }
}
