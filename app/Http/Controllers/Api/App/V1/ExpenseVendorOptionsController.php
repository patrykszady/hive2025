<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The vendor list for the app's expense form's vendor picker — this
 * section's own copy of VendorPickerOptionsController's data and semantics
 * (same fields: id/name/legal/city/state/type; same 'all' and 'project'
 * filters), kept independent so Expenses never depends on another
 * section's work in progress. See that controller's docblock for why the
 * shape is a flat, client-filterable list rather than a per-keystroke
 * search endpoint.
 */
class ExpenseVendorOptionsController extends Controller
{
    public const CACHE_SECONDS = 3600;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $request->boolean('all') && $user->vendor_role === 'Admin';
        $projectId = $request->integer('project') ?: null;

        $key = sprintf(
            'app:expenses:vendor-options:%s:%s:%s:%s',
            $user->vendor?->id ?? 0,
            $all ? 'all' : 'mine',
            $projectId ?? 0,
            $this->fingerprint(),
        );

        $vendors = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->vendors($all, $projectId));

        return response()->json(['data' => $vendors]);
    }

    /** Changes whenever a vendor is added, removed or edited. */
    private function fingerprint(): string
    {
        $row = Vendor::withoutGlobalScopes()->selectRaw('COUNT(*) AS total, MAX(updated_at) AS updated')->first();

        return md5(($row->total ?? 0).'|'.($row->updated ?? ''));
    }

    /**
     * @return list<array{id: int, name: string, legal: string, city: ?string, state: ?string, type: ?string}>
     */
    private function vendors(bool $all, ?int $projectId): array
    {
        $query = $all ? Vendor::withoutGlobalScopes() : Vendor::query();

        return $query
            ->select(['id', 'business_name', 'city', 'state', 'business_type'])
            // Embedded on a project's create form: only vendors billed to that project.
            ->when($projectId, fn ($q) => $q->whereHas('expenses', fn ($e) => $e->where('project_id', $projectId)))
            ->orderBy('business_name')
            ->get()
            ->map(fn (Vendor $vendor) => [
                'id' => (int) $vendor->id,
                'name' => (string) $vendor->name,
                'legal' => (string) $vendor->business_name,
                'city' => $vendor->city ?: null,
                'state' => $vendor->state ?: null,
                'type' => $vendor->business_type ?: null,
            ])
            ->values()
            ->all();
    }
}
