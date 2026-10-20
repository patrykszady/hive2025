<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Services\Projects\AppEmailTrackingDirectory;
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

    /**
     * The Projects tab's own "Email Tracking" card — unscoped (every
     * client-facing event the signed-in team can see) or narrowed to one
     * client with `?client_id=`, exactly like the web index's
     * `<livewire:projects.email-tracking-table :client-id="$client_id" />`
     * (App\Livewire\Projects\ProjectsIndex::hasEmailTrackingForClient()'s
     * own gate: visible to any team member, never Admin-only, and never to
     * a client-browsing user). See App\Services\Projects\
     * AppEmailTrackingDirectory for what is simplified versus the web
     * table's full thread-inference.
     */
    public function forClient(Request $request, AppEmailTrackingDirectory $directory): JsonResponse
    {
        abort_if($request->user()->is_browsing_as_client, 403);

        $clientId = $request->query('client_id');
        $page = max(1, (int) $request->query('cursor', 1));

        $paginator = $directory->events(is_numeric($clientId) ? (int) $clientId : null, $page);

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->hasMorePages() ? (string) ($page + 1) : null,
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
