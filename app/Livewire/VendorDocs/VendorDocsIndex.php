<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;

use App\Models\VendorDoc;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class VendorDocsIndex extends Component
{
    use AuthorizesRequests;

    public $view = null;
    public $date = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function vendors()
    {
        return Vendor::has('vendor_docs')->with('vendor_docs')
            ->withCount([
                'expenses',
                'expenses as expense_count' => function ($query) {
                    $query->where('created_at', '>=', today()->subYear());
                },
            ])
            ->orderBy('expense_count', 'DESC')
            ->get();
    }

    /**
     * Distinct vendor-doc TYPE count per vendor, in one query instead of the
     * `SELECT COUNT(DISTINCT type) FROM vendor_docs WHERE vendor_id = ?` each
     * VendorDocsCard placeholder ran for itself (34 extra queries on this
     * page). Unscoped like VendorDocsCard::render() — doc sharing across
     * companies is intentional there, see that file.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function vendorDocTypeCounts(): array
    {
        $vendorIds = $this->vendors->pluck('id');

        if ($vendorIds->isEmpty()) {
            return [];
        }

        return VendorDoc::withoutGlobalScopes()
            ->whereIn('vendor_id', $vendorIds)
            ->get(['vendor_id', 'type'])
            ->groupBy('vendor_id')
            ->map(fn ($docs) => $docs
                ->map(fn (VendorDoc $doc) => strtolower((string) ($doc->getRawOriginal('type') ?? $doc->type)))
                ->unique()
                ->count())
            ->all();
    }

    #[Title('Vendor Documents')]
    public function render()
    {
        $this->authorize('viewAny', VendorDoc::class);

        return view('livewire.vendor-docs.index');
    }
}
