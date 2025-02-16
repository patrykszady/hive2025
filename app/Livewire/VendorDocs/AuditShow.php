<?php

namespace App\Livewire\VendorDocs;

use App\Models\Check;
use App\Models\Transaction;
use App\Models\Vendor;
use Carbon\Carbon;
use Ilovepdf\Ilovepdf;
use Livewire\Attributes\Title;
use Livewire\Component;

class AuditShow extends Component
{
    public $bank_account_ids = null;

    public $end_date = null;

    public $audit_type = null;

    public $vendors_grouped_checks = null;

    public $transactions_no_check = null;

    public $vendor_docs = [];

    public $view = null;

    protected $queryString = [
        'end_date' => ['except' => ''],
        'bank_account_ids' => ['except' => ''],
        'audit_type' => ['except' => ''],
    ];

    public function mount()
    {
        $start_date = Carbon::parse($this->end_date)->subYear()->format('Y-m-d');
        $end_date = Carbon::parse($this->end_date)->format('Y-m-d');

        //check transactions with no CHECK set
        $this->transactions_no_check =
            Transaction::whereBetween('transaction_date', [$start_date, $end_date])
                ->whereIn('bank_account_id', $this->bank_account_ids)
                ->whereNotNull('check_number')
                ->whereNull('check_id')
                ->whereNull('expense_id')
                ->get();

        $this->vendors_grouped_checks =
            Check::whereBetween('date', [$start_date, $end_date])
                ->whereIn('bank_account_id', $this->bank_account_ids)
                ->whereNotNull('vendor_id')
                // ->where('vendor_id', 48)
                // ->with(['vendor'])
                ->where('vendor_id', '!=', auth()->user()->vendor->id)
                ->where('check_type', '!=', 'Cash')
                ->orderBy('date')
                ->get()
                ->groupBy('vendor_id')
                ->toBase();

        $this->vendor_docs = collect();
        foreach ($this->vendors_grouped_checks as $vendor_id => $vendor_checks) {
            $vendor = Vendor::findOrFail($vendor_id);
            // $vendor_checks->vendor = $vendor;
            $vendor_docs = $vendor->vendor_docs()->where('type', $this->audit_type)->get();

            foreach ($vendor_docs as $vendor_doc) {
                $doc_checks = $vendor_checks->whereBetween('date', [$vendor_doc->effective_date, $vendor_doc->expiration_date]);
                foreach ($doc_checks as $vendor_check) {
                    $vendor_check->covered = true;
                    $this->vendor_docs->push(storage_path('files/vendor_docs/'.$vendor_doc->doc_filename));
                }
            }
        }

        $this->vendors_grouped_checks = $this->vendors_grouped_checks->values();
        $this->vendor_docs = $this->vendor_docs->unique()->toArray();
        //also need GS COnstruction checks ...why gs to gs? no idea
    }

    public function download_documents()
    {
        // app('App\Http\Controllers\VendorDocsController')->audit_docs_pdf($this->vendor_docs);
        $filename = 'audit-'.auth()->user()->vendor->id.'-'.date('Y-m-d-h-m-s');

        //10-15-2023 Create cover page
        ///////cover page here/// use audit view? csv? table?

        $ilovepdf = new Ilovepdf(env('I_LOVE_PDF_PUBLIC'), env('I_LOVE_PDF_SECRET'));
        // Create a new task
        $myTaskMerge = $ilovepdf->newTask('merge');

        // Add files to task for upload
        foreach ($this->vendor_docs as $key => $file) {
            ${'merged_'.$key} = $myTaskMerge->addFile($file);
        }

        // dd($myTaskMerge);
        // $file1 = $myTaskMerge->addFile('/home/vagrant/web/gs/storage/files/vendor_docs/elm_r3.pdf');
        // $file2 = $myTaskMerge->addFile('/home/vagrant/web/gs/storage/files/vendor_docs/elm_r3.pdf');
        // Execute the task
        $myTaskMerge->setOutputFilename($filename);
        $myTaskMerge->execute();
        // $myTaskMerge->download();
        // Download the package files
        //storage_path('files/vendor_docs/')
        $myTaskMerge->download(storage_path('files/vendor_docs/'));

        // //stream/download
        $path = storage_path('files/vendor_docs/'.$filename.'.pdf');
        // $response = Response::make(file_get_contents($path), 200, [
        //     'Content-Type' => 'application/pdf'
        // ]);

        // $response;
        return response()->download($path);
    }

    #[Title('Audit')]
    public function render()
    {
        return view('livewire.vendor-docs.audit-show');
    }
}
