<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * The project page's Finances card, exactly as
 * App\Livewire\Projects\ProjectFinances shows it — `$project->finances` is
 * the shared accessor (App\Models\Project::finances()), scoped to the
 * viewing vendor via auth() the same way the web reads it. Admin-only, same
 * as the web's viewFinancials gate (a plain Member sees none of this
 * column on the web project page either).
 */
class ProjectFinancesController extends Controller
{
    public function show(Request $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewFinancials', $found);

        $finances = $found->finances;

        // The real card only shows Profit on Complete/Service Call projects.
        $status = $found->latestVendorStatus((int) $request->user()->vendor->id);
        $showProfit = in_array($status?->title, ['Complete', 'Service Call'], true);

        return response()->json([
            'data' => [
                'estimate' => $finances['estimate'],
                'change_orders' => $finances['change_orders'],
                'total_bid' => $finances['total_bid'],
                'reimbursments' => $finances['reimbursments'],
                'total_project' => $finances['total_project'],
                'expenses' => $finances['expenses'],
                'timesheets' => $finances['timesheets'],
                'total_cost' => $finances['total_cost'],
                'payments' => $finances['payments'],
                'balance' => $finances['balance'],
                'profit' => $showProfit ? $finances['profit'] : null,
            ],
        ]);
    }

    /**
     * A fresh, time-limited signed link to this project's Reimbursements
     * PDF — same App\Support\ProjectDocumentGenerator::generateReimbursements()
     * output ProjectFinances::print_reimbursements() streams on the web,
     * but reachable without a web session (the `signed` middleware alone
     * is the gate — see routes/web.php's projects.finances.reimbursements.
     * signed and ProjectReimbursementsPdfController::signed()).
     */
    public function reimbursementsPdfUrl(Request $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewFinancials', $found);

        $expiresAt = now()->addMinutes(10);

        return response()->json([
            'data' => [
                'url' => URL::temporarySignedRoute('projects.finances.reimbursements.signed', $expiresAt, ['project' => $found->id]),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs. */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        return $project;
    }
}
