<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\VendorResource;
use App\Models\Check;
use App\Models\Vendor;
use App\Services\Vendors\AppVendorDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The app's Vendors tab: the same directory, search and vendor detail as
 * the web Vendors index/show pages — see App\Services\Vendors\AppVendorDirectory
 * for the shared query and App\Livewire\Vendors\{VendorsIndex,VendorShow} for
 * the web components this mirrors. Create/edit stays web-only
 * (VendorPolicy::create/update gate a multi-step form — not simple enough
 * to port); this controller is otherwise read-only, except for the
 * `can_pay` flag it computes for VendorPaymentController's own routes (see
 * App\Services\Vendors\VendorPaymentOptions/VendorPaymentRecorder).
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

    /** A vendor the person may see: 404 when it doesn't exist anywhere, 403 when it isn't visible to their company (VendorPolicy::view). */
    private function visibleVendor(Request $request, int $id): Vendor
    {
        abort_unless(Vendor::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $vendor = Vendor::query()->whereKey($id)->first();
        abort_unless($vendor && $request->user()->can('view', $vendor), 403, 'You do not have access to this vendor.');

        return $vendor;
    }
}
