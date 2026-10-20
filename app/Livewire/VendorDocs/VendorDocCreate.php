<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;

use App\Services\VendorDocs\VendorDocRequester;

use App\Traits\ProcessesVendorDocs;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\VendorDoc;

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
        // Defense in depth: the blade only shows this button when
        // $canCreateDoc, but this callable method had no server-side check
        // of its own.
        $this->authorize('create', VendorDoc::class);

        $result = app(VendorDocRequester::class)->requestExpired($vendor, auth()->user()->vendor);

        if (! $result['sent']) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'info',
                heading: 'No Expired Documents',
                text: 'There are no expired vendor documents to request right now.',
            );

            return;
        }

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Document Requests Sent',
            text: 'License and insurance requests were routed to the appropriate recipients.',
        );
    }

    public function store()
    {
        // Defense in depth, same as requestDocument(): the blade only shows
        // this form when $canCreateDoc, but this callable method had no
        // server-side check of its own — matches the app API's
        // StoreVendorDocRequest::authorize() (VendorDocController::store()).
        $this->authorize('create', VendorDoc::class);

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
            } elseif ($result === 'duplicate') {
                // Duplicate policy(s) detected — not an error, inform user
                Flux::toast(
                    duration: 5000,
                    position: 'top right',
                    variant: 'warning',
                    heading: 'No New Documents',
                    text: 'This document appears to be a duplicate. No new policies were added.',
                );
            } elseif ($result === 'updated') {
                Flux::toast(
                    duration: 5000,
                    position: 'top right',
                    variant: 'success',
                    heading: 'Document Updated',
                    text: 'Existing policy records were updated to reference the new file.',
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
