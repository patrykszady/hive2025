<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreProjectRequest;
use App\Http\Requests\Api\App\UpdateProjectRequest;
use App\Http\Requests\Api\App\UpdateProjectStatusRequest;
use App\Http\Resources\Api\App\ProjectResource;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Services\Projects\AppProjectDirectory;
use App\Services\Projects\ProjectWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The app's Projects tab: the same cards, filters, search and status change
 * as the web Projects index and project page — see
 * App\Services\Projects\AppProjectDirectory for the shared query, and
 * App\Livewire\ProjectStatus\StatusCreate for the status-change logic this
 * mirrors.
 */
class ProjectController extends Controller
{
    private const PER_PAGE = 20;

    public function statuses(AppProjectDirectory $directory): JsonResponse
    {
        return response()->json(['data' => $directory->statuses()]);
    }

    public function index(Request $request, AppProjectDirectory $directory): JsonResponse
    {
        $user = $request->user();
        $vendorId = (int) $user->vendor->id;

        // Default (no status[] given) = Active only, like the web's bare
        // visit to /projects.
        $statusCodes = $request->has('status')
            ? $this->normalizeStatusCodes($request->query('status'))
            : [6];

        $clientId = $request->query('client_id');

        $page = $directory->query($user, $statusCodes, (string) $request->query('search', ''), is_numeric($clientId) ? (int) $clientId : null)
            ->cursorPaginate(self::PER_PAGE);

        $collection = $page->getCollection();
        Project::primeLatestVendorStatuses($collection);
        $directory->attachUpcomingTaskSummaries($collection);

        return response()->json([
            'data' => $collection->map(fn (Project $project) => (new ProjectResource($project))->forVendor($vendorId)->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * Create a project exactly like App\Livewire\Projects\ProjectCreate's
     * modal — Admin-only (App\Policies\ProjectPolicy::create), authorized by
     * StoreProjectRequest. See App\Services\Projects\ProjectWriter for the
     * address resolution, the client-accessible check, and the
     * double-submit guard this shares with the web.
     */
    public function store(StoreProjectRequest $request, ProjectWriter $writer): JsonResponse
    {
        $user = $request->user();
        $vendorId = (int) $user->vendor->id;

        $project = $writer->create(
            (int) $request->validated('client_id'),
            (string) $request->validated('project_name'),
            $request->validated(),
        );

        $project->load(['client.users', 'vendors']);
        Project::primeLatestVendorStatuses(collect([$project]));
        app(AppProjectDirectory::class)->attachUpcomingTaskSummaries(collect([$project]));

        return response()->json([
            'data' => (new ProjectResource($project))->forVendor($vendorId)->withDetail()->toArray($request),
        ], 201);
    }

    /**
     * Update a project exactly like App\Livewire\Projects\ProjectCreate's
     * "Update Project" modal — Admin-only (App\Policies\ProjectPolicy::
     * update, the owning vendor's Admin). See
     * App\Services\Projects\ProjectWriter::update() for the client-pivot
     * repoint and re-index this shares with the web.
     */
    public function update(UpdateProjectRequest $request, int $project, ProjectWriter $writer): JsonResponse
    {
        $found = $this->visibleProject($project);
        $this->authorize('update', $found);

        $user = $request->user();
        $vendorId = (int) $user->vendor->id;

        $writer->update($found, (int) $request->validated('client_id'), (string) $request->validated('project_name'), [
            'address' => $request->validated('address'),
            'address_2' => $request->validated('address_2'),
            'city' => $request->validated('city'),
            'state' => $request->validated('state'),
            'zip_code' => $request->validated('zip_code'),
        ]);

        $found = $found->fresh(['client.users', 'vendors']);
        Project::primeLatestVendorStatuses(collect([$found]));
        app(AppProjectDirectory::class)->attachUpcomingTaskSummaries(collect([$found]));

        return response()->json([
            'data' => (new ProjectResource($found))->forVendor($vendorId)->withDetail()->toArray($request),
        ]);
    }

    /**
     * Delete a project exactly like App\Livewire\Projects\ProjectCreate::
     * delete() — a soft delete, never forceDelete (see
     * App\Livewire\Forms\ProjectForm::delete()'s docblock for why).
     * App\Policies\ProjectPolicy::delete already blocks this when the
     * project has payments/timesheets/hours/tasks/expenses/distributions,
     * same as the web's delete-confirmation warning.
     */
    public function destroy(Request $request, int $project, ProjectWriter $writer): JsonResponse
    {
        $found = $this->visibleProject($project);
        $this->authorize('delete', $found);

        $writer->delete($found);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function show(Request $request, int $project): JsonResponse
    {
        $user = $request->user();
        $vendorId = (int) $user->vendor->id;

        $found = $this->visibleProject($project);
        $found->load(['client.users', 'vendors']);

        Project::primeLatestVendorStatuses(collect([$found]));
        app(AppProjectDirectory::class)->attachUpcomingTaskSummaries(collect([$found]));

        return response()->json([
            'data' => (new ProjectResource($found))->forVendor($vendorId)->withDetail()->toArray($request),
        ]);
    }

    public function updateStatus(UpdateProjectStatusRequest $request, int $project): JsonResponse
    {
        $user = $request->user();

        // Mirrors StatusCreate::update_project() — the only check there is
        // against client-browsing, never an Admin-only policy. The Hive
        // Contractors app is GS Construction's own team, so this is
        // practically a no-op, but it keeps the behaviour identical to the
        // web component this is reusing.
        if ($user->is_browsing_as_client) {
            abort(403);
        }

        $found = $this->visibleProject($project);
        $statusCode = (int) $request->validated('code');

        $status = ProjectStatus::create([
            'project_id' => $found->id,
            'belongs_to_vendor_id' => $user->vendor->id,
            'status_code' => $statusCode,
            'start_date' => today()->format('Y-m-d'),
        ]);

        if ($statusCode === 10) {
            $found->estimates()->delete();
        } else {
            $lastCancelled = $found->statuses()
                ->where('status_code', 10)
                ->where('created_at', '<', $status->created_at)
                ->orderByDesc('created_at')
                ->first();

            if ($lastCancelled) {
                $found->estimates()->onlyTrashed()
                    ->where('deleted_at', '>=', $lastCancelled->created_at)
                    ->restore();
            }
        }

        return response()->json([
            'status' => [
                'code' => $status->status_code,
                'title' => $status->title,
                'color' => $status->badge_color,
            ],
        ]);
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs (via ProjectScope). */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        return $project;
    }

    /**
     * Status codes from the `status[]` query param, each kept only when it
     * is a selectable status code — same rule as
     * ProjectsIndex::normalizeStatusCodes().
     *
     * @return list<int>
     */
    private function normalizeStatusCodes(mixed $value): array
    {
        $validCodes = array_column(ProjectStatus::selectableStatuses(), 'code');

        return collect(Arr::wrap($value))
            ->filter(fn ($code) => is_numeric($code))
            ->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => in_array($code, $validCodes, true))
            ->unique()
            ->values()
            ->all();
    }
}
