<?php

namespace App\Livewire\VendorDocs;

use App\Models\Agent;
use App\Models\Vendor;

use App\Jobs\SendVendorDocRequestEmail;

use App\Traits\ProcessesVendorDocs;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $this->validate();
        $docType = strtolower($this->doc_file->getClientOriginalExtension());

        // Sanitize filename
        $originalName = $this->doc_file->getClientOriginalName();
        $sanitizedName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $docType;
        $tempFilePath = "temp_vendor_docs/{$sanitizedName}";

        // Store file temporarily
        $this->doc_file->storeAs('temp_vendor_docs', $sanitizedName, 'files');

        try {
            // Process the document
            $result = $this->handleVendorDocProcessing(
                $tempFilePath,
                $docType,
                $this->vendor->id,
                auth()->user()->vendor->id
            );

            if ($result === false) {
                // Keep temp file for debugging on failure
                Flux::toast(
                    duration: 5000,
                    position: 'top right',
                    variant: 'danger',
                    heading: 'Processing Failed',
                    text: 'Unable to match vendor information from the document. Please verify the document contains correct vendor details.',
                );
            } else {
                Flux::toast(
                    duration: 5000,
                    position: 'top right',
                    variant: 'success',
                    heading: 'Vendor Document Added',
                    text: '',
                );

                $this->dispatch('refreshComponent')->to('vendor-docs.vendor-docs-card');
            }

        } catch (\Exception $e) {
            // Keep temp file for debugging on exception
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'danger',
                heading: 'Upload Failed',
                text: 'Error processing document: ' . $e->getMessage(),
            );
        }

        $this->modal('vendor_doc_form_modal')->close();
        $this->doc_file = null;
    }

    public function render()
    {
        return view('livewire.vendor-docs.form');
    }
}
