<?php

namespace App\Services\VendorDocs;

use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Traits\ProcessesVendorDocs;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The app's Vendor Documents screen — the same vendor list and per-vendor
 * document cards as the web's /vendor_docs pages
 * (App\Livewire\VendorDocs\{VendorDocsIndex,VendorDocsCard,VendorDocCreate}).
 * EWCCV scraping and the Audit report builder stay server-side/web — see
 * this track's final report.
 */
class AppVendorDocDirectory
{
    use ProcessesVendorDocs;

    /** VendorDocsIndex::vendors(). */
    public function vendors(): Collection
    {
        return Vendor::has('vendor_docs')->with('vendor_docs')
            ->withCount([
                'expenses',
                'expenses as expense_count' => fn ($q) => $q->where('created_at', '>=', today()->subYear()),
            ])
            ->orderBy('expense_count', 'DESC')
            ->get();
    }

    /** VendorDocsIndex::vendorDocTypeCounts(). */
    public function docTypeCounts(Collection $vendors): array
    {
        $vendorIds = $vendors->pluck('id');
        if ($vendorIds->isEmpty()) {
            return [];
        }

        return VendorDoc::withoutGlobalScopes()
            ->whereIn('vendor_id', $vendorIds)
            ->get(['vendor_id', 'type'])
            ->groupBy('vendor_id')
            ->map(fn ($docs) => $docs->map(fn (VendorDoc $doc) => strtolower((string) ($doc->getRawOriginal('type') ?? $doc->type)))->unique()->count())
            ->all();
    }

    /**
     * VendorDocsCard::render() — the latest document of each type, plus
     * everything older in a "previous" list.
     *
     * @return array{current: Collection<int, VendorDoc>, previous: Collection<int, VendorDoc>}
     */
    public function docsForVendor(Vendor $vendor): array
    {
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('expiration_date')
            ->with('agent')
            ->get();

        $current = $docs
            ->groupBy(fn (VendorDoc $doc) => strtolower((string) ($doc->getRawOriginal('type') ?? $doc->type)))
            ->map(fn ($group) => $group->first())
            ->values();

        $currentIds = $current->pluck('id')->all();
        $previous = $docs->reject(fn (VendorDoc $doc) => in_array($doc->id, $currentIds, true))->sortByDesc('expiration_date')->values();

        return ['current' => $current, 'previous' => $previous];
    }

    /**
     * Stores an uploaded certificate/license exactly as
     * VendorDocCreate::store() does, via ProcessesVendorDocs — same OCR
     * extraction, vendor matching and duplicate/renewal handling.
     *
     * @return true|'duplicate'|'updated'|false
     */
    public function store(Vendor $vendor, int $requestingVendorId, \Illuminate\Http\UploadedFile $file)
    {
        $docType = strtolower($file->getClientOriginalExtension());
        $sanitizedName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$docType;
        $tempFilePath = "temp_vendor_docs/{$sanitizedName}";

        $file->storeAs('temp_vendor_docs', $sanitizedName, 'files');

        return $this->handleVendorDocProcessing($tempFilePath, $docType, $vendor->id, $requestingVendorId);
    }
}
