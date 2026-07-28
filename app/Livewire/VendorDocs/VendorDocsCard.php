<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;
use App\Models\VendorDoc;

use Livewire\Attributes\Lazy;
use Livewire\Component;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

#[Lazy]
class VendorDocsCard extends Component
{
    use AuthorizesRequests;
    public Vendor $vendor;

    public $vendor_docs = [];
    public $view = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Column defs for the vendor-docs table — the real header row AND the
     * loading skeleton render from this one array, so widths can never drift.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Type', 'width' => 'w-[38%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Exp Date', 'width' => 'w-[30%]', 'skeleton' => 'badge'],
            ['label' => 'Policy #', 'width' => 'w-[32%] min-w-0', 'skeletonWidth' => 'w-28'],
        ];
    }

    /** Skeleton row ceiling — the card lists every document. */
    public static function placeholderRows(): int
    {
        return 6;
    }

    public function placeholder(array $params = []): \Illuminate\Contracts\View\View
    {
        $vendor = $params['vendor'] ?? null;
        $vendorId = $vendor instanceof Vendor ? $vendor->id : (is_numeric($vendor) ? (int) $vendor : null);

        // Cheap COUNT so the skeleton paints the rows that will actually
        // arrive — and none when the vendor has no documents.
        // render() collapses the docs to ONE row per type (latest of each), so
        // the skeleton counts distinct types — counting every document painted
        // 6 shimmer rows for a card that renders 3.
        $rows = $vendorId
            ? min(
                VendorDoc::withoutGlobalScopes()
                    ->where('vendor_id', $vendorId)
                    ->distinct()
                    ->count('type'),
                static::placeholderRows()
            )
            : static::placeholderRows();

        return view('livewire.vendor-docs.placeholder', [
            'expanded' => !($params['view'] ?? false),
            'view' => $params['view'] ?? false,
            'vendor' => $vendor,
            'rows' => $rows,
        ]);
    }

    public function render()
    {
        $this->authorize('create', VendorDoc::class);
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('vendor_id', $this->vendor->id)
            ->orderByDesc('expiration_date')
            ->with('agent')
            ->get();

        $this->vendor_docs = $docs
            ->groupBy(function (VendorDoc $doc) {
                return strtolower((string) ($doc->getRawOriginal('type') ?? $doc->type));
            })
            ->map(fn ($group) => $group->first())
            ->values();

        foreach ($this->vendor_docs as $doc) {
            if ($doc->expiration_date <= today()) {
                $this->vendor->expired_docs = true;
            }
        }

        return view('livewire.vendor-docs.card');
    }
}
