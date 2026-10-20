<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ProjectCommunicationsIndexRequest;
use App\Models\Project;
use App\Services\Communications\ProjectCommunicationsTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The app's Communications tab on a project: the same merged call/text/
 * email timeline ProjectCommunicationsTimeline hands the web project page,
 * paged newest-first with `before`. Open to anyone who can view the
 * project at all (ProjectPolicy::view) — the web has no narrower,
 * Member-only restriction on this data to mirror (Finances/Materials/
 * Vendors/Email-Tracking are the only project-page cards gated past plain
 * view(), and none of them covers calls/texts/emails).
 */
class ProjectCommunicationsController extends Controller
{
    public function index(ProjectCommunicationsIndexRequest $request, int $project, ProjectCommunicationsTimeline $timeline): JsonResponse
    {
        $found = $this->visibleProject($project);

        $before = $request->validated('before') ? Carbon::parse((string) $request->validated('before')) : null;
        $kinds = $request->validated('kinds') ?: ProjectCommunicationsTimeline::KINDS;
        $limit = (int) ($request->validated('limit') ?: 30);

        $page = $timeline->forProject($found, $before, $limit, $kinds);

        return response()->json([
            'data' => $page['items'],
            'next_before' => $page['next_before'],
        ]);
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs — see BidController::visibleProject(). */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        Gate::authorize('view', $project);

        return $project;
    }
}
