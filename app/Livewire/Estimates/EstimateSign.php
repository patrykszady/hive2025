<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateSignature;
use Flux;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EstimateSign extends Component
{
    #[Locked]
    public ?int $estimateId = null;

    #[Locked]
    public bool $valid = false;

    #[Locked]
    public bool $alreadySigned = false;

    #[Locked]
    public ?string $pdfUrl = null;

    public string $signerName = '';

    public bool $typeSignature = false;

    public string $signatureData = '';

    public ?Estimate $estimate = null;

    public function mount(Estimate $estimate): void
    {
        $this->estimateId = $estimate->id;

        $estimate = Estimate::withoutGlobalScopes()
            ->with([
                'vendor',
                'project.client.users',
                'estimate_sections.estimate_line_items',
                'signature',
            ])
            ->find($estimate->id);

        if (! $estimate || ! $estimate->vendor) {
            $this->valid = false;

            return;
        }

        // Check if estimate has payments (finalized)
        if (empty($estimate->payments)) {
            $this->valid = false;

            return;
        }

        $this->estimate = $estimate;
        $this->valid = true;

        if ($estimate->signature) {
            $this->alreadySigned = true;
            $this->signerName = $estimate->signature->signer_name;
        }

        // Generate signed URL for inline PDF preview
        $this->pdfUrl = URL::temporarySignedRoute(
            'estimate.sign.pdf',
            now()->addHours(24),
            ['estimate' => $estimate->id]
        );
    }

    public function sign(): void
    {
        $this->validate([
            'signerName' => 'required|string|min:2|max:255',
            'signatureData' => 'required|string',
        ], [
            'signerName.required' => 'Please type your name to sign.',
            'signatureData.required' => 'Please draw or type your signature.',
        ]);

        $estimate = Estimate::withoutGlobalScopes()
            ->with(['estimate_sections', 'vendor', 'project.client'])
            ->find($this->estimateId);

        if (! $estimate || $estimate->isSigned()) {
            $this->alreadySigned = true;

            return;
        }

        // Generate document hash for tamper detection
        $documentHash = hash('sha256', json_encode([
            'estimate_id' => $estimate->id,
            'sections' => $estimate->estimate_sections->pluck('id', 'total')->toArray(),
            'payments' => $estimate->payments,
            'options' => $estimate->options,
        ]));

        EstimateSignature::create([
            'estimate_id' => $estimate->id,
            'signer_name' => $this->signerName,
            'signer_email' => $estimate->client?->users?->first()?->email,
            'signature_data' => $this->signatureData,
            'signature_type' => $this->typeSignature ? 'type' : 'draw',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'document_hash' => $documentHash,
            'signed_at' => now(),
        ]);

        $this->alreadySigned = true;

        Flux::toast(
            text: 'Contract signed successfully!',
            variant: 'success',
        );
    }

    public function render()
    {
        return view('livewire.estimates.sign')
            ->layout('components.layouts.guest');
    }
}
