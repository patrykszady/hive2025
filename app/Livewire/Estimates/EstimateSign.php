<?php

namespace App\Livewire\Estimates;

use App\Mail\EstimateSigningInvite;
use App\Models\Estimate;
use App\Models\EstimateSignature;
use App\Models\User;
use Flux;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EstimateSign extends Component
{
    // Steps: not-authorized | vendor-must-sign | sign | done

    #[Locked]
    public ?int $estimateId = null;

    #[Locked]
    public bool $valid = false;

    #[Locked]
    public ?string $pdfUrl = null;

    public ?Estimate $estimate = null;

    public string $step = 'sign';

    /** Whether the current user is a vendor signer (vs client signer) */
    #[Locked]
    public bool $isVendorSigner = false;

    #[Locked]
    public ?int $matchedUserId = null;

    #[Locked]
    public ?string $matchedUserName = null;

    #[Locked]
    public ?string $matchedUserEmail = null;

    #[Locked]
    public ?string $matchedUserPhone = null;

    // -- Signing fields --
    public string $signerName = '';

    public bool $nameVerified = false;

    public bool $typeSignature = false;

    public string $signatureData = '';

    public function mount(Estimate $estimate): void
    {
        // Strip legacy signed URL query params — redirect to clean URL
        if (request()->has('expires') || request()->has('signature')) {
            $this->redirect(route('estimate.sign', $estimate), navigate: true);

            return;
        }

        $this->estimateId = $estimate->id;

        $estimate = Estimate::withoutGlobalScopes()
            ->with([
                'vendor.users',
                'project.client.users',
                'estimate_sections.estimate_line_items',
                'signatures',
            ])
            ->find($estimate->id);

        if (! $estimate || ! $estimate->vendor) {
            $this->valid = false;

            return;
        }

        if (empty($estimate->payments)) {
            $this->valid = false;

            return;
        }

        $this->estimate = $estimate;
        $this->valid = true;

        // Generate PDF URL (auth-protected, no signature needed)
        $this->pdfUrl = route('estimate.sign.pdf', ['estimate' => $estimate->id]);

        $authUser = auth()->user();

        // 1. Check if user is a vendor user for this estimate
        $vendorUserIds = $estimate->vendor->users->pluck('id');
        if ($vendorUserIds->contains($authUser->id)) {
            $this->isVendorSigner = true;

            // Already signed?
            if ($estimate->signatures->contains('user_id', $authUser->id)) {
                $this->step = 'done';

                return;
            }

            // Fully signed? (vendor + all clients)
            if ($estimate->isFullySigned()) {
                $this->step = 'done';

                return;
            }

            $this->matchedUserId = $authUser->id;
            $this->matchedUserName = trim($authUser->first_name . ' ' . $authUser->last_name);
            $this->matchedUserEmail = $authUser->email;
            $this->matchedUserPhone = $this->formatPhoneForSms($authUser->cell_phone);
            $this->step = 'sign';

            return;
        }

        // 2. Check if user is a client user for this estimate
        $clientUsers = $estimate->project?->client?->users ?? collect();
        $matchedClient = $clientUsers->firstWhere('id', $authUser->id);

        if (! $matchedClient) {
            $this->step = 'not-authorized';

            return;
        }

        // Client user — but has vendor signed yet?
        if (! $estimate->isVendorSigned()) {
            $this->step = 'vendor-must-sign';

            return;
        }

        // Already signed?
        if ($estimate->signatures->contains('user_id', $matchedClient->id)) {
            $this->step = 'done';

            return;
        }

        // Fully signed?
        if ($estimate->isFullySigned()) {
            $this->step = 'done';

            return;
        }

        $this->isVendorSigner = false;
        $this->matchedUserId = $matchedClient->id;
        $this->matchedUserName = trim($matchedClient->first_name . ' ' . $matchedClient->last_name);
        $this->matchedUserEmail = $matchedClient->email;
        $this->matchedUserPhone = $this->formatPhoneForSms($matchedClient->cell_phone);
        $this->step = 'sign';
    }

    // ==============================================================
    // Computed Properties
    // ==============================================================

    /**
     * All client users who need to sign.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function requiredSigners(): mixed
    {
        return $this->estimate?->project?->client?->users ?? collect();
    }

    /**
     * Existing signatures on this estimate.
     *
     * @return \Illuminate\Support\Collection<int, EstimateSignature>
     */
    #[Computed]
    public function existingSignatures(): mixed
    {
        return $this->estimate?->signatures ?? collect();
    }

    /**
     * Client users who haven't signed yet.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function pendingSigners(): mixed
    {
        $signedUserIds = $this->existingSignatures->pluck('user_id')->filter()->all();

        return $this->requiredSigners->reject(
            fn (User $user) => in_array($user->id, $signedUserIds)
        );
    }

    // ==============================================================
    // Auto-verify signer name on input change
    // ==============================================================

    public function updatedSignerName(): void
    {
        $this->nameVerified = false;
        $this->resetErrorBag('signerName');

        if (empty(trim($this->signerName))) {
            return;
        }

        // Normalize: lowercase, collapse whitespace, extract words
        $normalize = fn (string $name) => preg_split('/\s+/', mb_strtolower(trim($name)));

        $inputWords = $normalize($this->signerName);
        $expectedWords = $normalize($this->matchedUserName ?? '');

        if (count($inputWords) < 2 || count($expectedWords) < 2) {
            $this->addError('signerName', 'Please enter your first and last name.');
            return;
        }

        $inputFirst = head($inputWords);
        $inputLast = last($inputWords);
        $expectedFirst = head($expectedWords);
        $expectedLast = last($expectedWords);

        if ($inputFirst === $expectedFirst && $inputLast === $expectedLast) {
            $this->nameVerified = true;
        } else {
            $this->addError('signerName', 'Name does not match your account name.');
        }
    }

    // ==============================================================
    // Sign the contract
    // ==============================================================

    public function sign(): void
    {
        if (! auth()->check() || ! $this->matchedUserId) {
            return;
        }

        $this->validate([
            'signerName' => 'required|string|min:2|max:255',
            'signatureData' => 'required|string',
        ], [
            'signerName.required' => 'Please type your name to sign.',
            'signatureData.required' => 'Please draw or type your signature.',
        ]);

        $estimate = Estimate::withoutGlobalScopes()
            ->with(['estimate_sections', 'vendor.users', 'project.client.users', 'signatures'])
            ->find($this->estimateId);

        if (! $estimate) {
            return;
        }

        // Prevent duplicate
        if ($estimate->signatures()->where('user_id', $this->matchedUserId)->exists()) {
            $this->step = 'done';

            return;
        }

        $documentHash = hash('sha256', json_encode([
            'estimate_id' => $estimate->id,
            'sections' => $estimate->estimate_sections->pluck('id', 'total')->toArray(),
            'payments' => $estimate->payments,
            'options' => $estimate->options,
        ]));

        EstimateSignature::create([
            'estimate_id' => $estimate->id,
            'user_id' => $this->matchedUserId,
            'signer_name' => $this->signerName,
            'signer_email' => $this->matchedUserEmail,
            'signer_phone' => $this->matchedUserPhone,
            'signature_data' => $this->signatureData,
            'signature_type' => $this->typeSignature ? 'type' : 'draw',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'document_hash' => $documentHash,
            'signed_at' => now(),
        ]);

        // If vendor just signed, email all client users
        if ($this->isVendorSigner) {
            $this->sendSigningInvitesToClients($estimate);
        }

        // Refresh estimate
        $this->estimate = $estimate->fresh([
            'vendor.users',
            'project.client.users',
            'signatures',
        ]);

        $this->step = 'done';

        Flux::toast(
            text: 'Contract signed successfully!',
            variant: 'success',
        );
    }

    // ==============================================================
    // Helpers
    // ==============================================================

    /**
     * Send signing invite emails to all client users on this estimate.
     */
    protected function sendSigningInvitesToClients(Estimate $estimate): void
    {
        $clientUsers = $estimate->project?->client?->users ?? collect();

        foreach ($clientUsers as $user) {
            if (! $user->email) {
                continue;
            }

            Mail::mailer('mailtrap-sdk')->to($user->email)->send(
                new EstimateSigningInvite($estimate, $user->first_name ?? '')
            );
        }
    }

    protected function formatPhoneForSms(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        return str_starts_with($digits, '+') ? $digits : '+' . $digits;
    }

    public function render()
    {
        return view('livewire.estimates.sign')
            ->layout('components.layouts.guest');
    }
}
