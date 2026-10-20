<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The vendor list every vendor picker on the site filters in the browser
 * (resources/views/components/vendor-picker.blade.php, resources/js/vendor-picker.js).
 *
 * One compact JSON document per visible set — 851 vendors is about 60 KB —
 * fetched once per page and shared by every picker on it. Flux renders one
 * listbox option as 1.3 KB of HTML, so 851 options in a listbox would weigh
 * 1.1 MB, which is why the pickers searched the server on every keystroke
 * before, and flashed while they did (Patryk, 2026-10-11).
 *
 * Cached until any vendor changes (the fingerprint is the vendors table's
 * row count and newest updated_at) and answered with an ETag, so a browser
 * that already holds the list gets a 304.
 */
class VendorPickerOptionsController extends Controller
{
    public const CACHE_SECONDS = 3600;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $all = $request->boolean('all') && $user->vendor_role === 'Admin';
        $projectId = $request->integer('project') ?: null;
        $byYearSpend = $request->query('order') === 'ytd';
        $fingerprint = $this->fingerprint();

        $key = sprintf('vendor-picker:%s:%s:%s:%s:%s', $user->vendor?->id ?? 0, $all ? 'all' : 'mine', $projectId ?? 0, $byYearSpend ? 'ytd' : 'name', $fingerprint);
        $etag = '"'.md5($key).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=300'];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->noContent(304, $headers);
        }

        $vendors = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->vendors($all, $projectId, $byYearSpend));

        return response()->json(['version' => $fingerprint, 'vendors' => $vendors], 200, $headers);
    }

    /** Changes whenever a vendor is added, removed or edited. */
    protected function fingerprint(): string
    {
        $row = Vendor::withoutGlobalScopes()->selectRaw('COUNT(*) AS total, MAX(updated_at) AS updated')->first();

        return md5(($row->total ?? 0).'|'.($row->updated ?? ''));
    }

    /**
     * @return list<array{id: int, name: string, legal: string, city: ?string, state: ?string, type: ?string}>
     */
    protected function vendors(bool $all, ?int $projectId, bool $byYearSpend = false): array
    {
        $query = $all ? Vendor::withoutGlobalScopes() : Vendor::query();

        return $query
            ->select(['id', 'business_name', 'city', 'state', 'business_type'])
            // Embedded on a project page: only vendors billed to that project.
            ->when($projectId, fn ($q) => $q->whereHas('expenses', fn ($e) => $e->where('project_id', $projectId)))
            // The task modal's order: most spent with over the last year first,
            // so the vendors the crew actually uses open the list.
            ->when($byYearSpend, fn ($q) => $q
                ->withSum(['expenses as year_spend' => fn ($e) => $e->where('date', '>=', today()->subYear())], 'amount')
                ->orderByDesc('year_spend'))
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
