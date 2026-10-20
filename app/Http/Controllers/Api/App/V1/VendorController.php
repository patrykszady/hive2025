<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreVendorRequest;
use App\Http\Requests\Api\App\UpdateVendorRequest;
use App\Http\Resources\Api\App\VendorResource;
use App\Models\Check;
use App\Models\Vendor;
use App\Services\People\AppPersonResolver;
use App\Services\Vendors\AppVendorDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The app's Vendors tab: the same directory, search, vendor detail,
 * "New Vendor"/"Edit Vendor"/"Add Existing Vendor" as the web Vendors
 * index/show pages — see App\Services\Vendors\AppVendorDirectory for the
 * shared query/write logic and App\Livewire\Vendors\{VendorsIndex,
 * VendorShow,VendorCreate} for the web components this mirrors. The
 * `can_pay` flag is computed here too, for VendorPaymentController's own
 * routes (see App\Services\Vendors\VendorPaymentOptions/VendorPaymentRecorder).
 */
class VendorController extends Controller
{
    private const PER_PAGE = 20;

    public function types(): JsonResponse
    {
        return response()->json(['data' => AppVendorDirectory::TYPES]);
    }

    public function index(Request $request, AppVendorDirectory $directory): JsonResponse
    {
        $canManage = Gate::allows('create', Vendor::class);
        $canPay = Gate::allows('create', Check::class);
        $type = $directory->normalizeType($request->query('type'));

        $page = $directory->query($type, (string) $request->query('search', ''))
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Vendor $vendor) => (new VendorResource($vendor))->canManage($canManage)->canPay($canPay)->toArray($request))
                ->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            // The web's "Add New Vendor" button —
            // @can('create', App\Models\Vendor::class) on
            // resources/views/livewire/vendors/partials/index-actions.blade.php.
            'can_create_vendor' => $canManage,
        ]);
    }

    public function show(Request $request, int $vendor): JsonResponse
    {
        $found = $this->visibleVendor($request, $vendor);
        $canManage = $request->user()->can('update', $found);
        $canPay = $request->user()->can('create', Check::class);

        return response()->json([
            'data' => (new VendorResource($found))->canManage($canManage)->canPay($canPay)->withDetail()->toArray($request),
        ]);
    }

    /**
     * "New Vendor"'s live business-name search —
     * VendorCreate::updatedBusinessNameText(). Open to anyone who can see
     * the Vendors tab; VendorPolicy::create gates the actual create/link
     * actions, same as the web gates the "Create New Vendor" button but
     * not the search results themselves.
     */
    public function search(Request $request, AppVendorDirectory $directory): JsonResponse
    {
        $search = trim((string) $request->query('name', ''));
        $company = $request->user()->vendor;

        if ($search === '' || mb_strlen($search) < 3 || ! $company) {
            return response()->json(['data' => ['existing' => [], 'linkable' => []]]);
        }

        $matches = $directory->businessNameMatches($company, $search);

        return response()->json([
            'data' => [
                'existing' => $matches['existing']->map(fn (Vendor $v) => $this->searchRow($v))->values()->all(),
                'linkable' => $matches['linkable']->map(fn (Vendor $v) => $this->searchRow($v))->values()->all(),
            ],
        ]);
    }

    /**
     * The owner-phone lookup VendorCreate's nested "Add Owner" step runs
     * before showing name/email fields — UserCreate::user_cell_find() for
     * the vendor branch, condensed to just the lookup (the "already
     * belongs to this vendor" error only applies to an EXISTING vendor,
     * which doesn't exist yet here).
     */
    public function ownerLookup(Request $request, AppPersonResolver $people): JsonResponse
    {
        $request->validate(['cell_phone' => ['required', 'digits:10']]);

        $person = $people->findByPhone($request->string('cell_phone')->value());

        return response()->json([
            'data' => $person ? [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
            ] : null,
        ]);
    }

    /** "Create New Vendor" — VendorCreate::store() + VendorForm::store(). */
    public function store(StoreVendorRequest $request, AppVendorDirectory $directory): JsonResponse
    {
        $this->authorize('create', Vendor::class);

        $data = $request->validated();
        if (! empty($data['owner_cell_phone'])) {
            $data['owner'] = [
                'cell_phone' => $data['owner_cell_phone'],
                'first_name' => $data['owner_first_name'] ?? null,
                'last_name' => $data['owner_last_name'] ?? null,
                'email' => $data['owner_email'] ?? null,
            ];
        }

        $vendor = $directory->createVendor($request->user(), $data);
        $canManage = $request->user()->can('update', $vendor);
        $canPay = $request->user()->can('create', Check::class);

        return response()->json([
            'data' => (new VendorResource($vendor))->canManage($canManage)->canPay($canPay)->withDetail()->toArray($request),
        ], 201);
    }

    /** "Edit Vendor" — VendorForm::update(). */
    public function update(UpdateVendorRequest $request, int $vendor, AppVendorDirectory $directory): JsonResponse
    {
        $found = $this->visibleVendor($request, $vendor);
        $this->authorize('update', $found);

        $directory->updateVendor($found, $request->validated());
        $canManage = $request->user()->can('update', $found);
        $canPay = $request->user()->can('create', Check::class);

        return response()->json([
            'data' => (new VendorResource($found->refresh()))->canManage($canManage)->canPay($canPay)->withDetail()->toArray($request),
        ]);
    }

    /**
     * "Add" an existing, not-yet-connected vendor —
     * VendorCreate::addVendorToCompany(). $vendor is looked up without the
     * visibility scope (it isn't connected to this company yet, so
     * VendorPolicy::view would always refuse it) — isLinkableBy() inside
     * the service is the real gate.
     */
    public function link(Request $request, int $vendor, AppVendorDirectory $directory): JsonResponse
    {
        $this->authorize('create', Vendor::class);

        abort_unless(Vendor::withoutGlobalScopes()->whereKey($vendor)->exists(), 404);
        $found = Vendor::withoutGlobalScopes()->whereKey($vendor)->first();

        $directory->linkVendor($request->user()->vendor, $found);

        return response()->json([
            'data' => (new VendorResource($found))->canManage($request->user()->can('update', $found))->canPay($request->user()->can('create', Check::class))->withDetail()->toArray($request),
        ]);
    }

    /**
     * @return array{id: int, name: string, business_type: string}
     */
    private function searchRow(Vendor $vendor): array
    {
        return ['id' => $vendor->id, 'name' => $vendor->name, 'business_type' => $vendor->business_type];
    }

    /** A vendor the person may see: 404 when it doesn't exist anywhere, 403 when it isn't visible to their company (VendorPolicy::view). */
    private function visibleVendor(Request $request, int $id): Vendor
    {
        abort_unless(Vendor::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $vendor = Vendor::query()->whereKey($id)->first();
        abort_unless($vendor && $request->user()->can('view', $vendor), 403, 'You do not have access to this vendor.');

        return $vendor;
    }
}
