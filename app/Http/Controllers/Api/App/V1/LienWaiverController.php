<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreLienWaiverRequest;
use App\Http\Resources\Api\App\LienWaiverResource;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Models\Check;
use App\Models\Expense;
use App\Models\LienWaiver;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\LienWaivers\LienWaiverCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Lien Waivers tab — Admin-only, exactly like the web:
 * LienWaiverPolicy's viewAny/create require vendor_role === 'Admin', and
 * update/delete additionally require the acting vendor to be the one that
 * ISSUED the waiver (belongs_to_vendor_id), never the recipient sub — see
 * App\Livewire\LienWaivers\Index's sendForSignature()/cancel()/delete().
 *
 * Left web-only: the GCSS sworn-statement/draw flow (openSwornStatement,
 * generateSwornStatement, editDraw, …) — a many-vendor paperwork wizard
 * this task's "request, send, …" scope doesn't call for and that doesn't
 * fit a phone screen. The app's "Request a waiver" mirrors only the
 * standalone, single-waiver createWaiver() action. There is also no manual
 * "mark received/signed" action on the web to mirror — a scanned, wet-
 * signed waiver flips to Signed automatically via WaiverScanIngest.
 */
class LienWaiverController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Enforces LienWaiverPolicy::viewAny (Admin-only) explicitly — the web's
     * Index/Show Livewire components rely only on LienWaiverScope plus the
     * nav link being Admin-gated (see AppSectionPermissions), so a Member
     * who typed the URL directly could technically still view it there. The
     * app holds the line the web's own nav intends: a Member sees nothing
     * through this endpoint either.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LienWaiver::class);

        $projectId = $request->query('project_id');
        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('search', ''));

        $query = LienWaiver::with(['vendor', 'payerVendor', 'project'])
            ->when(is_numeric($projectId), fn ($q) => $q->where('project_id', (int) $projectId))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $needle = '%'.$search.'%';
                $q->where(function ($inner) use ($needle) {
                    $inner->whereHas('vendor', fn ($v) => $v->where('business_name', 'like', $needle))
                        ->orWhereHas('project', fn ($p) => $p->where('project_name', 'like', $needle)
                            ->orWhere('address', 'like', $needle));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (LienWaiver $waiver) => (new LienWaiverResource($waiver))->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, int $lienWaiver): JsonResponse
    {
        $found = $this->visibleWaiver($lienWaiver);
        $this->authorize('view', $found);

        $found->load(['vendor', 'payerVendor', 'project.client', 'project.estimates', 'check', 'payment']);

        return response()->json(['data' => (new LienWaiverResource($found))->withDetail()->toArray($request)]);
    }

    /**
     * Candidate projects for a new waiver — mirrors
     * App\Livewire\LienWaivers\Index::availableProjects().
     */
    public function eligibleProjects(Request $request): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $projects = Project::query()
            ->with('latestStatus')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->project_name,
                'address' => $project->short_address,
                'status' => $project->latestStatus ? [
                    'code' => $project->latestStatus->status_code,
                    'title' => $project->latestStatus->title,
                ] : null,
            ]);

        return response()->json(['data' => $projects->values()->all()]);
    }

    /**
     * Candidate claimants for a sub/supplier waiver on a project — mirrors
     * App\Livewire\LienWaivers\Index::projectSubVendors(), exposed so the
     * app's "Request a waiver" screen can offer the same vendor picker the
     * web's create modal shows once a project is chosen.
     */
    public function subVendors(Request $request): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        abort_unless($contractor, 422, 'Your account is missing a vendor association.');

        $project = Project::query()->find($request->query('project_id'));
        abort_unless($project, 404);

        $vendors = $this->projectSubVendors($project, $contractor)
            ->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->business_name,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $vendors]);
    }

    /**
     * A single, ad-hoc lien waiver — mirrors
     * App\Livewire\LienWaivers\Index::createWaiver() via
     * App\Services\LienWaivers\LienWaiverCreator.
     */
    public function store(StoreLienWaiverRequest $request, LienWaiverCreator $creator): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        abort_unless($contractor, 422, 'Your account is missing a vendor association.');

        $project = Project::query()->find($request->validated('project_id'));
        abort_unless($project, 404);

        $type = LienWaiverType::from($request->validated('type'));
        $claimantId = (int) ($request->validated('claimant_vendor_id') ?: $contractor->id);
        $isSub = $claimantId !== (int) $contractor->id;

        if ($isSub && ! $this->projectSubVendors($project, $contractor)->contains('id', $claimantId)) {
            return response()->json(['message' => 'That vendor is not on this project.'], 422);
        }

        $waiver = $creator->create(
            $project,
            $contractor,
            $type,
            $claimantId,
            $request->validated('amount') !== null ? str_replace(',', '', (string) $request->validated('amount')) : null,
            (string) $request->validated('through_date'),
            [
                'name' => (string) $request->validated('payer_name'),
                'address' => (string) $request->validated('payer_address'),
                'city_state_zip' => (string) $request->validated('payer_city_state_zip'),
            ],
            $request->validated('notes'),
            $request->user()->id,
        );

        $waiver->load(['vendor', 'payerVendor', 'project.client', 'check', 'payment']);

        return response()->json(['data' => (new LienWaiverResource($waiver))->withDetail()->toArray($request)], 201);
    }

    /** Mirrors App\Livewire\LienWaivers\Index::sendForSignature(). */
    public function send(Request $request, int $lienWaiver): JsonResponse
    {
        $waiver = $this->visibleWaiver($lienWaiver);
        $this->authorize('update', $waiver);

        if ($waiver->isSigned() || $waiver->status === LienWaiverStatus::Cancelled) {
            return response()->json(['message' => 'This waiver can no longer be (re)sent.'], 422);
        }

        $sendAt = $waiver->payerVendor?->nextBusinessHoursOpening() ?? now();
        SendLienWaiverSigningRequestJob::dispatch($waiver->id)->delay($sendAt);

        return response()->json([
            'message' => $sendAt->isFuture()
                ? 'Lien waiver queued for '.($waiver->vendor?->business_name ?? 'vendor').' — sending '.$sendAt->diffForHumans().' ('.$sendAt->format('D g:i A').'), during business hours.'
                : 'Lien waiver queued for delivery to '.($waiver->vendor?->business_name ?? 'vendor').'.',
        ]);
    }

    /** Mirrors App\Livewire\LienWaivers\Index::cancel(). */
    public function cancel(Request $request, int $lienWaiver): JsonResponse
    {
        $waiver = $this->visibleWaiver($lienWaiver);
        $this->authorize('update', $waiver);

        if ($waiver->isSigned()) {
            return response()->json(['message' => 'A signed waiver cannot be cancelled.'], 422);
        }

        $waiver->forceFill(['status' => LienWaiverStatus::Cancelled])->save();

        return response()->json(['data' => (new LienWaiverResource($waiver->fresh(['vendor', 'payerVendor', 'project'])))->toArray($request)]);
    }

    /** Mirrors App\Livewire\LienWaivers\Index::delete(). */
    public function destroy(Request $request, int $lienWaiver): JsonResponse
    {
        $waiver = $this->visibleWaiver($lienWaiver);
        $this->authorize('delete', $waiver);

        if ($waiver->isSigned()) {
            return response()->json(['message' => 'A signed waiver cannot be deleted.'], 422);
        }

        $waiver->delete();

        return response()->json(['message' => 'Lien waiver deleted.']);
    }

    /** A waiver the current user may see at all (either party — LienWaiverScope). 404 when it doesn't exist/isn't visible. */
    private function visibleWaiver(int $id): LienWaiver
    {
        $waiver = LienWaiver::query()->find($id);
        abort_unless($waiver, 404);

        return $waiver;
    }


    /**
     * Candidate claimants for a sub→GC waiver — mirrors
     * App\Livewire\LienWaivers\Index::projectSubVendors().
     *
     * @return \Illuminate\Support\Collection<int, Vendor>
     */
    private function projectSubVendors(Project $project, Vendor $contractor): \Illuminate\Support\Collection
    {
        $gcId = (int) $contractor->id;
        $projectId = (int) $project->id;

        $paymentVendorIds = Payment::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->distinct('belongs_to_vendor_id')
            ->pluck('belongs_to_vendor_id');

        $checkVendorIds = Check::withoutGlobalScopes()
            ->whereIn('id', function ($q) use ($projectId) {
                $q->select('check_id')
                    ->from('payments')
                    ->where('project_id', $projectId)
                    ->whereNotNull('check_id');
            })
            ->distinct('vendor_id')
            ->pluck('vendor_id');

        $expenseVendorIds = Expense::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->distinct('vendor_id')
            ->pluck('vendor_id');

        $ids = $paymentVendorIds->merge($checkVendorIds)
            ->merge($expenseVendorIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $gcId)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Vendor::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'address', 'city', 'state', 'zip_code']);
    }
}
