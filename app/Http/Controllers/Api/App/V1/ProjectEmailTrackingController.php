<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The project page's email activity, read-only. Reuses
 * EmailTracking::clientFacing()/forProjectAndItsLeads() — the same base
 * query App\Livewire\Projects\EmailTrackingTable uses — ordered newest
 * first. Simplified from the web table: this skips its
 * sent/opened/replied row-collapsing (one row per raw event here, not one
 * row per inferred email thread). Admin-only, nested under the web's
 * viewFinancials + update gates on the project page.
 */
class ProjectEmailTrackingController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewFinancials', $found);
        Gate::authorize('update', $found);

        $events = EmailTracking::clientFacing()
            ->forProjectAndItsLeads($found->id)
            ->orderByDesc('event_at')
            ->limit(self::PER_PAGE)
            ->get();

        return response()->json([
            'data' => $events->map(fn (EmailTracking $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'email_template_name' => $event->email_template_name,
                'recipient_emails' => $event->recipient_emails ?? [],
                'event_at' => $event->event_at?->toIso8601String(),
            ])->values()->all(),
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
