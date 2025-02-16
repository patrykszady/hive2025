<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;
// use App\Models\Check;
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

    #[Title('Certificates')]
    public function render()
    {
        $this->authorize('viewAny', VendorDoc::class);
        // $this->date['start'] = today()->subYear(1)->format('Y-m-d');
        // $this->date['end'] = today()->format('Y-m-d');

        // $checks = Check::whereBetween('date', [$this->date['start'], $this->date['end']])->whereNull('user_id')->get()->groupBy('vendor_id');

        // dd($checks);

        //get latest for each type only
        //['vendor_id', 'type']
        //->orderBy('type', 'DESC')
        // $docs = VendorDoc::with('vendor')->orderBy('expiration_date', 'DESC')->get()->groupBy('vendor_id');
        // dd($docs);

        // foreach($vendors as $vendor){
        //     $doc_types = $vendor->vendor_docs()->orderBy('expiration_date', 'DESC')->with('agent')->get()->groupBy('type');

        //     foreach($doc_types as $type_certificates)
        //     {
        //         if($type_certificates->first()->expiration_date <= today()){
        //             $vendor->expired_docs = TRUE;
        //         }
        //     }
        // }

        return view('livewire.vendor-docs.index');
    }
}
