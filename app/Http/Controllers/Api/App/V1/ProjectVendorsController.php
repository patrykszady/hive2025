<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\InviteProjectVendorRequest;
use App\Mail\VendorProjectInvite;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

/**
 * The project page's Vendors card, exactly as
 * App\Livewire\Projects\ProjectVendors shows and invites — Admin-only
 * (@can('update', $project) on the web page), judged at the project's
 * OWNING vendor like every other project-management ability.
 */
class ProjectVendorsController extends Controller
{
    public function index(Request $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('update', $found);

        $invitedVendorIds = $found->vendors->pluck('id')->flip();

        $available = $request->user()->vendor
            ->vendors()
            ->where('vendors.business_type', 'Sub')
            ->withSum([
                'expenses as ytd_expense_sum' => fn ($query) => $query->where('date', '>=', today()->subYear()),
            ], 'amount')
            ->orderByDesc('ytd_expense_sum')
            ->orderBy('business_name')
            ->get()
            ->unique('id')
            ->sortBy(fn (Vendor $vendor) => $invitedVendorIds->has($vendor->id) ? 0 : 1)
            ->values();

        return response()->json([
            'data' => [
                'invited' => $found->vendors->map(fn (Vendor $vendor) => [
                    'id' => $vendor->id,
                    'business_name' => $vendor->business_name,
                    'business_phone' => $vendor->business_phone,
                ])->values()->all(),
                'available' => $available->map(fn (Vendor $vendor) => [
                    'id' => $vendor->id,
                    'business_name' => $vendor->business_name,
                    'ytd_expense_sum' => (float) ($vendor->ytd_expense_sum ?? 0),
                    'invited' => $invitedVendorIds->has($vendor->id),
                ])->values()->all(),
            ],
        ]);
    }

    /** Mirrors ProjectVendors::save(): attach, an Invited status row, then the dev-email notification. */
    public function store(InviteProjectVendorRequest $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('update', $found);

        $vendor = Vendor::findOrFail($request->validated('vendor_id'));

        if ($vendor->projects->contains($found->id)) {
            return response()->json(['message' => 'Vendor is already part of this project.'], 422);
        }

        $vendor->projects()->attach($found->id, ['client_id' => $found->client_id]);

        $statusCode = ProjectStatus::getCodeForLabel('Invited') ?? 1;
        ProjectStatus::create([
            'project_id' => $found->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => $statusCode,
            'start_date' => today()->format('Y-m-d'),
        ]);

        $devEmail = config('mail.dev_email') ?? 'patryk@hive.contractors';
        Mail::to($devEmail)->send(new VendorProjectInvite(
            invitingVendor: $request->user()->vendor,
            invitedVendor: $vendor,
            project: $found,
        ));

        return response()->json(['data' => ['id' => $vendor->id, 'business_name' => $vendor->business_name]], 201);
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
