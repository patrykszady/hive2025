<?php

namespace App\Livewire\VendorDocs;

use App\Jobs\SendVendorDocRequestEmail;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class VendorDocCreate extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Vendor $vendor;

    // public VendorDoc $vendor_doc;
    public $doc_file = null;

    protected $listeners = ['addDocument', 'requestDocument', 'downloadDocuments'];

    protected function rules()
    {
        return [
            'doc_file' => 'required|mimes:pdf,jpg,jpeg,png',
        ];
    }

    public function addDocument(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->modal('vendor_doc_form_modal')->show();
    }

    public function downloadDocuments($doc_filenames)
    {
        dd('in downloadDocuments');
        $this->vendor = $vendor;
        $this->modal('vendor_doc_form_modal')->show();
    }

    public function requestDocument(Vendor $vendor)
    {
        // dd('in requestDocument');
        $doc_types = $vendor->vendor_docs()->orderBy('expiration_date', 'DESC')->with('agent')->get()->groupBy('type');

        $latest_docs = collect();
        foreach ($doc_types as $type_certificates) {
            if ($type_certificates->first()->expiration_date <= today()) {
                $latest_docs->push($type_certificates->first());
            }
        }

        $agent_ids = $latest_docs->groupBy('agent_id');

        foreach ($agent_ids as $agent_id => $agent_expired_docs) {
            $agent = Agent::find($agent_id);

            //if no agent, send to Vendor only
            if (! is_null($agent)) {
                $agent_email = $agent->email;
            } else {
                $agent_email = $vendor->business_email;
            }

            $requesting_vendor = auth()->user()->vendor;

            //send email to agent, vendor, and auth()->vendor() with all $agent_expired_docs
            SendVendorDocRequestEmail::dispatch($agent_expired_docs, $vendor, $requesting_vendor, $agent_email);

            $this->dispatch('notify',
                type: 'success',
                content: 'Vendor Document Requested'
                // route: 'expenses/' . $expense->id
            );
        }
    }

    public function store()
    {
        //validate, file must be pdf, jpg, png
        $this->validate();
        // $this->authorize('update', $this->expense);
        $doc_type = $this->doc_file->getClientOriginalExtension();
        $ocr_filename = $this->vendor->id.'-'.auth()->user()->vendor->id.'-'.date('Y-m-d-H-i-s').'.'.$doc_type;
        $file_location = 'files/vendor_docs/'.$ocr_filename;
        //save file for this->vendor
        $this->doc_file->storeAs('vendor_docs', $ocr_filename, 'files');
        $document_model = env('AZURE_CUSTOM_MODEL_COI');

        //send to form recogrnizer
        $insurance_info = app(\App\Http\Controllers\ReceiptController::class)->azure_docs_api($file_location, $document_model, $doc_type);
        $insurance_info = $insurance_info['analyzeResult']['documents'][0]['fields'];

        //save/update Agent from the certificate
        if (isset($insurance_info['agent_email']['valueString'])) {
            $agent = Agent::where('email', $insurance_info['agent_email']['valueString'])->first();

            if (is_null($agent)) {
                $agent = Agent::create([
                    'name' => isset($insurance_info['agent_name']['valueString']) ? $insurance_info['agent_name']['valueString'] : null,
                    'business_name' => isset($insurance_info['agent_agency']['valueString']) ? $insurance_info['agent_agency']['valueString'] : null,
                    'address' => isset($insurance_info['agent_agency_address']['valueString']) ? $insurance_info['agent_agency_address']['valueString'] : null,
                    'phone' => isset($insurance_info['agent_phone']['content']) ? $insurance_info['agent_phone']['content'] : null,
                    'email' => isset($insurance_info['agent_email']['valueString']) ? $insurance_info['agent_email']['valueString'] : null,
                ]);
            }
        }

        foreach ($insurance_info['general_multi']['valueArray'] as $general_policy) {
            $general_policy_object = $general_policy['valueObject'];
            $general_policy_object['number'] = $general_policy_object['general_policy_number']['valueString'];
            $general_policy_object['eff'] = $general_policy_object['general_eff']['valueDate'];
            $general_policy_object['exp'] = $general_policy_object['general_exp']['valueDate'];

            //check if exists, if exists, continue and dont save again
            $vendor_doc = VendorDoc::where('number', $general_policy_object['number'])
                ->where('expiration_date', $general_policy_object['exp'])->first();

            if (is_null($vendor_doc)) {
                $vendor_doc = VendorDoc::create([
                    'type' => 'general',
                    'vendor_id' => $this->vendor->id,
                    'effective_date' => $general_policy_object['eff'],
                    'expiration_date' => $general_policy_object['exp'],
                    'number' => $general_policy_object['number'],
                    'belongs_to_vendor_id' => auth()->user()->vendor->id,
                    'doc_filename' => $ocr_filename,
                ]);

                //link agent and insurance
                if (isset($agent)) {
                    $vendor_doc->agent_id = $agent->id;
                    $vendor_doc->save();
                }
            }
        }

        foreach ($insurance_info['workers_multi']['valueArray'] as $workers_policy) {
            $workers_policy_object = $workers_policy['valueObject'];
            $workers_policy_object['number'] = $workers_policy_object['workers_policy_number']['valueString'];
            $workers_policy_object['eff'] = $workers_policy_object['workers_eff']['valueDate'];
            $workers_policy_object['exp'] = $workers_policy_object['workers_exp']['valueDate'];

            //check if exists, if exists, continue and dont save again
            $vendor_doc = VendorDoc::where('number', $workers_policy_object['number'])
                ->where('expiration_date', $workers_policy_object['exp'])->first();

            if (is_null($vendor_doc)) {
                $vendor_doc = VendorDoc::create([
                    'type' => 'workers',
                    'vendor_id' => $this->vendor->id,
                    'effective_date' => $workers_policy_object['eff'],
                    'expiration_date' => $workers_policy_object['exp'],
                    'number' => $workers_policy_object['number'],
                    'belongs_to_vendor_id' => auth()->user()->vendor->id,
                    'doc_filename' => $ocr_filename,
                ]);

                //link agent and insurance
                if (isset($agent)) {
                    $vendor_doc->agent_id = $agent->id;
                    $vendor_doc->save();
                }
            }
        }

        //error ... already exists
        //create vendor_doc for each $insurance_info
        // $vendor_docs = [];
        // if(isset($insurance_info['general_policy_number']['valueString'])){
        //     //check if exists
        //     $vendor_doc = VendorDoc::where('number', $insurance_info['general_policy_number']['valueString'])
        //         ->where('expiration_date', $insurance_info['general_exp']['valueDate'])->first();

        //     if(is_null($vendor_doc)){
        //         $vendor_docs[] = 'general';
        //     }
        // }

        // if(isset($insurance_info['workers_policy_number']['valueString'])){
        //     if(str_replace(' ', '', $insurance_info['workers_policy_number']['valueString']) != 'N/A'){
        //         //check if exists
        //         $vendor_doc = VendorDoc::where('number', $insurance_info['workers_policy_number']['valueString'])
        //             ->where('expiration_date', $insurance_info['workers_exp']['valueDate'])->first();

        //         if(is_null($vendor_doc)){
        //             $vendor_docs[] = 'workers';
        //         }
        //     }
        // }

        // foreach($vendor_docs as $vendor_doc){
        //     $vendor_doc = VendorDoc::create([
        //         'type' => $vendor_doc,
        //         'vendor_id' => $this->vendor->id,
        //         'effective_date' => $insurance_info[$vendor_doc . '_eff']['valueDate'],
        //         'expiration_date' => $insurance_info[$vendor_doc . '_exp']['valueDate'],
        //         'number' => $insurance_info[$vendor_doc . '_policy_number']['valueString'],
        //         'belongs_to_vendor_id' => auth()->user()->vendor->id,
        //         'doc_filename' => $ocr_filename
        //     ]);

        //     //link agent and insurance
        //     if(isset($agent)){
        //         $vendor_doc->agent_id = $agent->id;
        //         $vendor_doc->save();
        //     }
        // }

        $this->modal('vendor_doc_form_modal')->close();
        $this->doc_file = null;

        $this->dispatch('refreshComponent')->to('vendor-docs.vendor-docs-card');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Document Added',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.vendor-docs.form');
    }
}
