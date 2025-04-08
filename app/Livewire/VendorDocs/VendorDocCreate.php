<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;

use App\Jobs\SendVendorDocRequestEmail;

use App\Traits\ProcessesVendorDocs;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Component;
use Livewire\WithFileUploads;

class VendorDocCreate extends Component
{
    use AuthorizesRequests, WithFileUploads, ProcessesVendorDocs;

    public Vendor $vendor;
    public $doc_file;

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

            // dd([$vendor, $requesting_vendor]);
            //send email to agent, vendor, and auth()->vendor() with all $agent_expired_docs
            SendVendorDocRequestEmail::dispatch($agent_expired_docs, $vendor, $requesting_vendor, $agent_email);

            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'success',
                heading: 'Insurace Requested',
                // route / href / wire:click
                text: '',
            );
        }
    }

    public function store()
    {
        $docType = strtolower($this->doc_file->getClientOriginalExtension());
        $tempFilePath = "temp_vendor_docs/{$this->doc_file->getClientOriginalName()}";

        // Store the uploaded file temporarily
        $this->doc_file->storeAs('temp_vendor_docs', $this->doc_file->getClientOriginalName(), 'files');

        // Process the documenthandleVendorDocProcessing
        $this->handleVendorDocProcessing(
            $tempFilePath,
            $docType,
            $this->vendor->id,
            auth()->user()->vendor->id
        );

        // Reset input and notify the user
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
