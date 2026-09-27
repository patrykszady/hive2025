<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Citation;
use App\Support\Citations\ListingPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SsSystems\Platform\Citations\CitationsAdminActions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ported from gsc's/jpeterson's `Api/Admin/V1/CitationsController.php`,
 * now the SAME thin HTTP adapter over the kit's `Citations\
 * CitationsAdminActions` those two sites use (citations-admin-actions,
 * 2026-09-27) — no hive-specific controller code survives. This host's
 * honest "not set up" answers (`UnavailableSession`/`UnavailableBatchRunner`/
 * `UnavailableVerificationInbox`, bound in `AppServiceProvider`) fall out
 * of the SAME service code every other site runs; see that service's own
 * docblock for exactly how (2026-09-27 audit, citations.md #8: "the
 * controller itself duplicates ~150 lines of 'always answer ok:false'
 * glue that would disappear entirely if it delegated to the SAME service
 * class"). `payload()` (`ListingPayload::make()`) and `screenshot()` (a
 * file response, not JSON) stay here in full, since neither is shared
 * logic — no noVNC viewer route is registered on this host, but
 * `screenshot()`/`find()` never depend on one.
 */
class CitationsController extends Controller
{
    public function __construct(protected CitationsAdminActions $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->index()]);
    }

    public function payload(): JsonResponse
    {
        return response()->json(['data' => ListingPayload::make()]);
    }

    /** Always the one honest refusal on this host — see CitationsAdminActions's own docblock for why. */
    public function batch(): JsonResponse
    {
        return response()->json(['data' => $this->service->batch([], [])]);
    }

    public function start(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->service->start($slug, false)]);
    }

    public function poll(): JsonResponse
    {
        return response()->json(['data' => $this->service->poll()]);
    }

    public function resume(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->service->resume($slug)]);
    }

    public function stop(): JsonResponse
    {
        return response()->json(['data' => $this->service->stop()]);
    }

    /** The manual edit form — the real way a citation moves on this app. */
    public function update(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Citation::STATUSES)],
            'listing_url' => ['nullable', 'url', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'account_email' => ['nullable', 'email', 'max:191'],
        ]);

        return response()->json(['data' => $this->service->update($slug, $data)]);
    }

    public function screenshot(string $slug, string $file): BinaryFileResponse
    {
        $citation = $this->service->find($slug);
        abort_unless(preg_match('/^[a-z0-9._-]+\.(png|jpg)$/i', $file), 404);
        $path = $this->service->sessionDir($citation).'/shots/'.$file;
        abort_unless(is_file($path), 404);

        return response()->file($path);
    }
}
