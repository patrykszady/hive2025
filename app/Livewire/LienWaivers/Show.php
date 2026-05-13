<?php

namespace App\Livewire\LienWaivers;

use App\Enums\LienWaiverStatus;
use App\Models\LienWaiver;
use App\Models\LienWaiverSignature;
use App\Support\LienWaiverDocumentGenerator;
use Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $waiverId;

    /** When true, this view was loaded via a public access_token URL. */
    #[Locked]
    public bool $publicAccess = false;

    // -- Signing fields (mirrors EstimateSign) --
    public string $signerName = '';

    public string $signerTitle = '';

    public bool $nameVerified = false;

    public bool $typeSignature = false;

    public string $signatureData = '';

    #[Locked]
    public ?string $matchedUserName = null;

    #[Locked]
    public ?string $matchedUserEmail = null;

    public function mount(?LienWaiver $lienWaiver = null, ?string $token = null): void
    {
        if ($token) {
            $waiver = LienWaiver::withoutGlobalScopes()
                ->where('access_token', $token)
                ->first();

            abort_unless($waiver, 404);

            $this->publicAccess = true;
            $this->waiverId = $waiver->id;
        } else {
            abort_unless($lienWaiver && $lienWaiver->exists, 404);
            $this->waiverId = $lienWaiver->id;
        }

        if ($user = auth()->user()) {
            $this->matchedUserName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            $this->matchedUserEmail = $user->email;
        }
    }

    #[Computed]
    public function waiver(): LienWaiver
    {
        $query = $this->publicAccess
            ? LienWaiver::withoutGlobalScopes()
            : LienWaiver::query();

        return $query
            ->with(['vendor', 'payerVendor', 'project.client', 'project.estimates', 'check', 'payment', 'signatures'])
            ->findOrFail($this->waiverId);
    }

    #[Computed]
    public function canSign(): bool
    {
        $waiver = $this->waiver;

        if ($waiver->isSigned() || $waiver->status === LienWaiverStatus::Cancelled) {
            return false;
        }

        if ($this->publicAccess) {
            return true;
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Authenticated vendor users on the receiving side can sign.
        return $user->vendor && (int) $user->vendor->id === (int) $waiver->vendor_id;
    }

    /**
     * Auto-verify the signer name against the authenticated user's name,
     * mirroring the estimate signing flow. For public token-based access
     * (no auth user), the name is accepted as-is.
     */
    public function updatedSignerName(): void
    {
        $this->nameVerified = false;
        $this->resetErrorBag('signerName');

        if (empty(trim($this->signerName))) {
            return;
        }

        // Public signers (no account) self-attest — accept any non-empty name.
        if ($this->publicAccess || ! $this->matchedUserName) {
            $this->nameVerified = true;
            return;
        }

        $normalize = fn (string $name) => preg_split('/\s+/', mb_strtolower(trim($name)));

        $inputWords = $normalize($this->signerName);
        $expectedWords = $normalize($this->matchedUserName ?? '');

        if (count($inputWords) < 2 || count($expectedWords) < 2) {
            $this->addError('signerName', 'Please enter your first and last name.');
            return;
        }

        if (head($inputWords) === head($expectedWords) && last($inputWords) === last($expectedWords)) {
            $this->nameVerified = true;
        } else {
            $this->addError('signerName', 'Name does not match your account name.');
        }
    }

    public function sign(): void
    {
        $waiver = $this->waiver;

        if (! $this->canSign) {
            abort(403);
        }

        $this->validate([
            'signerName' => 'required|string|min:2|max:255',
            'signerTitle' => 'required|string|min:2|max:255',
            'signatureData' => 'required|string',
        ], [
            'signerName.required' => 'Please type your full legal name.',
            'signerTitle.required' => 'Please enter your title.',
            'signatureData.required' => 'Please draw or type your signature.',
        ]);

        DB::transaction(function () use ($waiver) {
            LienWaiverSignature::create([
                'lien_waiver_id' => $waiver->id,
                'user_id' => auth()->id(),
                'signer_name' => $this->signerName,
                'signer_title' => $this->signerTitle,
                'signer_email' => auth()->user()?->email,
                'signature_data' => $this->signatureData,
                'signature_type' => $this->typeSignature ? 'type' : 'draw',
                'ip_address' => request()->ip() ?? '0.0.0.0',
                'user_agent' => substr((string) request()->userAgent(), 0, 1024),
                'document_hash' => $waiver->computeDocumentHash(),
                'signed_at' => now(),
            ]);

            $waiver->forceFill([
                'status' => LienWaiverStatus::Signed,
                'signed_at' => now(),
                'document_hash' => $waiver->computeDocumentHash(),
            ])->save();
        });

        // Re-render & persist a "signed" PDF copy for download.
        try {
            LienWaiverDocumentGenerator::generate($waiver->refresh(), store: true);
        } catch (\Throwable $e) {
            report($e);
        }

        unset($this->waiver, $this->canSign);

        Flux::toast(
            text: 'Lien waiver signed. Thank you!',
            variant: 'success',
        );
    }

    #[Title('Lien Waiver')]
    public function render()
    {
        return view('livewire.lien-waivers.show');
    }
}
