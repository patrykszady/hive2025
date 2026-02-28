<?php

namespace App\Livewire\Estimates;

use App\Mail\EstimateFullySigned;
use App\Mail\EstimateSigningInvite;
use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\EstimateSignature;
use App\Models\User;
use App\Support\EstimateDocumentGenerator;
use Flux;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EstimateSign extends Component
{
    // Steps: vendor-must-sign | sign | done

    #[Locked]
    public ?int $estimateId = null;

    #[Locked]
    public ?string $contractHtml = null;

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

        abort_unless($estimate && $estimate->vendor, 404);
        abort_unless(! empty($estimate->payments), 404, 'Estimate has not been finalized yet.');

        $authUser = auth()->user();

        // Determine if user is an admin vendor user or a client user for this estimate
        $vendorAdminIds = $estimate->vendor->users
            ->filter(fn ($u) => $u->pivot->role_id == 1 && $u->pivot->is_employed)
            ->pluck('id');
        $clientUsers = $estimate->project?->client?->users ?? collect();
        $isVendorAdmin = $vendorAdminIds->contains($authUser->id);
        $isClientUser = $clientUsers->contains('id', $authUser->id);

        abort_unless($isVendorAdmin || $isClientUser, 403, 'You are not authorized to view this estimate.');

        $this->estimate = $estimate;

        // Generate contract HTML for inline rendering
        $this->contractHtml = $this->buildContractHtml($estimate);

        // 1. Vendor admin signer flow
        if ($isVendorAdmin) {
            $this->isVendorSigner = true;
            $requiredVendorSignerIds = $estimate->required_vendor_signer_ids;

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

            // If specific signers are configured, only those users may sign — others can still view
            if ($requiredVendorSignerIds->isNotEmpty() && ! $requiredVendorSignerIds->contains($authUser->id)) {
                $this->step = 'done';

                return;
            }

            // When no specific signers configured, only one vendor user needs to sign
            if ($requiredVendorSignerIds->isEmpty() && $estimate->isVendorSigned()) {
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

        // 2. Client user flow
        $matchedClient = $clientUsers->firstWhere('id', $authUser->id);

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

        // If vendor just signed AND all required vendor signers are done, email client users
        if ($this->isVendorSigner) {
            $freshEstimate = $estimate->fresh(['vendor.users', 'signatures']);
            if ($freshEstimate->isVendorSigned()) {
                $this->sendSigningInvitesToClients($estimate);
            }
        }

        // Refresh estimate
        $this->estimate = $estimate->fresh([
            'vendor.users',
            'project.client.users',
            'signatures',
        ]);

        // If fully signed, generate and store the signed contract PDF, then notify everyone
        if ($this->estimate->isFullySigned() && ! $this->estimate->signed_contract_path) {
            $this->generateAndStoreSignedPdf($this->estimate);
            $this->sendFullySignedNotifications($this->estimate);
        }

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
     * Generate the fully-signed estimate PDF and store it to disk.
     */
    protected function generateAndStoreSignedPdf(Estimate $estimate): void
    {
        try {
            $result = EstimateDocumentGenerator::generate($estimate);

            $vendorId = $estimate->belongs_to_vendor_id;
            $filename = 'signed_contracts/' . $vendorId . '/' . $estimate->id . '_' . now()->timestamp . '.pdf';

            Storage::disk('local')->put($filename, $result['binary']);

            $estimate->update(['signed_contract_path' => $filename]);
            $this->estimate = $estimate->fresh();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Download the stored signed contract PDF.
     */
    public function downloadSignedContract(): mixed
    {
        $path = $this->estimate->signed_contract_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            Flux::toast(text: 'Signed contract not found.', variant: 'danger');

            return null;
        }

        $this->skipRender();

        $filename = 'Signed Contract - Estimate ' . $this->estimate->number . '.pdf';

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

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

    /**
     * Send "fully signed" email with attached PDF to all vendor admins and client users.
     */
    protected function sendFullySignedNotifications(Estimate $estimate): void
    {
        try {
            $vendor = $estimate->vendor;

            // Send to the vendor's business email
            if ($vendor?->business_email) {
                Mail::mailer('mailtrap-sdk')->to($vendor->business_email)->send(
                    new EstimateFullySigned($estimate, $vendor->short_name ?? '', isClient: false)
                );
            }

            // Send to all client users
            $clientUsers = $estimate->project?->client?->users ?? collect();

            foreach ($clientUsers as $user) {
                if (! $user->email) {
                    continue;
                }

                Mail::mailer('mailtrap-sdk')->to($user->email)->send(
                    new EstimateFullySigned($estimate, $user->first_name ?? '', isClient: true)
                );
            }
        } catch (\Throwable $e) {
            report($e);
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

    /**
     * Build the contract HTML for inline rendering on the signing page.
     */
    protected function buildContractHtml(Estimate $estimate): ?string
    {
        $vendor = $estimate->vendor;
        $project = $estimate->project;
        $client = $project?->client ?? $estimate->client;
        $timezone = vendor_timezone();

        $contractTemplate = EmailTemplate::withoutGlobalScopes()
            ->where('vendor_id', $vendor->id)
            ->where('type', 'contract')
            ->first();

        if (! $contractTemplate) {
            return null;
        }

        $estimateTotal = $estimate->estimate_sections->sum('total');
        $estimateTotalWords = ucwords(
            Number::spell((int) $estimateTotal) . ' dollars and ' .
            Number::spell((int) (($estimateTotal - (int) $estimateTotal) * 100)) . ' cents'
        );

        $paymentScheduleHtml = EstimateDocumentGenerator::renderPaymentSchedule($estimate->payments);

        return EstimateDocumentGenerator::renderContractTemplate($contractTemplate->body, [
            'today_date' => now()->setTimezone($timezone)->format('m/d/Y'),
            'vendor_name' => $vendor->business_name ?? 'Unknown Vendor',
            'short_vendor_name' => data_get($vendor->options, 'short_name') ?: ($vendor->business_name ?? 'Unknown Vendor'),
            'client_name' => $client?->name ?? 'Unknown Client',
            'estimate_number' => $estimate->number,
            'project_address' => $project?->full_address ?? 'No address on file',
            'start_date' => $estimate->start_date?->format('m/d/Y') ?? 'START_DATE_HERE',
            'end_date' => $estimate->end_date?->format('m/d/Y') ?? 'END_DATE_HERE',
            'estimate_total' => money($estimateTotal),
            'estimate_total_words' => $estimateTotalWords,
            'payment_schedule' => $paymentScheduleHtml,
            'current_year' => now()->setTimezone($timezone)->format('Y'),
        ]);
    }

    public function render()
    {
        return view('livewire.estimates.sign');
    }
}
