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

    #[Title('Vendor Documents')]
    public function render()
    {
        $this->authorize('viewAny', VendorDoc::class);

        return view('livewire.vendor-docs.index');
    }
}
