<?php

namespace App\Livewire\LienWaivers;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Models\LienWaiver;
use App\Models\Project;
use Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Placeholder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?Project $project = null;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    // Manual create form -----------------------------------------------------
    public bool $showCreate = false;

    public bool $showProjectSelector = false;

    public ?int $selectedProjectForCreate = null;

    public string $newType = LienWaiverType::ConditionalProgress->value;

    public ?string $newAmount = null;

    public ?string $newThroughDate = null;

    public string $newPayerName = '';

    public string $newPayerAddress = '';

    public string $newPayerCityStateZip = '';

    public string $newNotes = '';

    public ?int $newPaymentId = null;

    #[Placeholder]
    public function skeleton()
    {
        return view('livewire.lien-waivers.skeleton');
    }

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->project = $project;
            $this->prefillPayerFromProject();
            $this->newThroughDate = now()->toDateString();
        }
    }

    /**
     * Default the payer fields to the project client's billing address,
     * falling back to the project's site address when no client is on file.
     */
    protected function prefillPayerFromProject(): void
    {
        if (! $this->project) {
            return;
        }

        $client = $this->project->client;

        $this->newPayerName = $client?->name ?? '';
        $this->newPayerAddress = trim((string) ($client?->address ?? $this->project->address ?? ''));
        $this->newPayerCityStateZip = trim(sprintf(
            '%s%s %s',
            $client?->city ?? $this->project->city ?? '',
            ($client?->state ?? $this->project->state)
                ? ', ' . ($client?->state ?? $this->project->state)
                : '',
            $client?->zip_code ?? $this->project->zip_code ?? '',
        ));
    }

    #[Computed]
    public function waivers()
    {
        return LienWaiver::query()
            ->with(['vendor', 'payerVendor', 'project', 'check', 'payment'])
            ->when($this->project?->id, fn ($q) => $q->where('project_id', $this->project->id))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($q) {
                $needle = '%' . $this->search . '%';
                $q->where(function ($q) use ($needle) {
                    $q->whereHas('vendor', fn ($v) => $v->where('business_name', 'like', $needle))
                        ->orWhereHas('project', fn ($p) => $p->where('project_name', 'like', $needle)
                            ->orWhere('address', 'like', $needle));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    #[Computed]
    public function statusOptions(): array
    {
        return collect(LienWaiverStatus::cases())
            ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])
            ->all();
    }

    #[Computed]
    public function typeOptions(): array
    {
        return collect(LienWaiverType::cases())
            ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])
            ->all();
    }

    #[Computed]
    public function availableProjects()
    {
        return Project::query()
            ->with('latestStatus')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'project_name' => $p->project_name,
                'short_address' => $p->short_address,
                'status' => $p->latestStatus,
            ]);
    }

    public function openProjectSelector(): void
    {
        $this->showProjectSelector = true;
    }

    public function selectProjectAndCreate(int $projectId): void
    {
        $project = Project::find($projectId);

        if (! $project) {
            Flux::toast(text: 'Project not found.', variant: 'danger');
            return;
        }

        $this->project = $project;
        $this->selectedProjectForCreate = $projectId;
        $this->showProjectSelector = false;
        $this->openCreate();
    }

    public function openCreate(): void
    {
        if (! $this->project) {
            return;
        }

        $this->authorize('create', LienWaiver::class);

        $this->resetValidation();
        $this->newType = LienWaiverType::ConditionalProgress->value;
        $this->newAmount = null;
        $this->newThroughDate = now()->toDateString();
        $this->prefillPayerFromProject();
        $this->newNotes = '';
        $this->newPaymentId = null;
        unset($this->projectPayments);
        $this->showCreate = true;
    }

    /**
     * Payments already recorded on this project — shown in the create modal
     * so the waiver can be issued against a specific payment (amount and
     * through-date follow the selection, and the waiver links to it).
     */
    #[Computed]
    public function projectPayments()
    {
        if (! $this->project) {
            return collect();
        }

        return \App\Models\Payment::withoutGlobalScopes()
            ->where('project_id', $this->project->id)
            ->with('lienWaiver:id,payment_id,status,type')
            ->orderByDesc('date')
            ->get();
    }

    public function selectPayment(?int $paymentId): void
    {
        // Clicking the selected payment again deselects it.
        if ($paymentId === null || $this->newPaymentId === $paymentId) {
            $this->newPaymentId = null;

            return;
        }

        $payment = $this->projectPayments->firstWhere('id', $paymentId);

        if (! $payment) {
            return;
        }

        $this->newPaymentId = $payment->id;
        $this->newAmount = number_format((float) $payment->amount, 2, '.', '');
        $this->newThroughDate = optional($payment->date)->toDateString() ?? $this->newThroughDate;
    }

    public function createWaiver(): void
    {
        if (! $this->project) {
            return;
        }

        $user = auth()->user();
        $contractor = $user?->vendor;

        if (! $contractor) {
            Flux::toast(text: 'Your account is missing a vendor association.', variant: 'danger');
            return;
        }

        $type = LienWaiverType::from($this->newType);
        $isPaidInFull = $type === LienWaiverType::UnconditionalFinal;

        $this->validate([
            'newType' => ['required', Rule::in(array_column(LienWaiverType::cases(), 'value'))],
            'newAmount' => [$isPaidInFull ? 'nullable' : 'required', 'nullable', 'numeric', 'gte:0'],
            'newThroughDate' => ['required', 'date'],
            'newPayerName' => ['required', 'string', 'max:255'],
            'newPayerAddress' => ['nullable', 'string', 'max:255'],
            'newPayerCityStateZip' => ['nullable', 'string', 'max:255'],
            'newNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'newAmount.gte' => 'Amount cannot be negative.',
        ]);

        $notesPayload = [
            'payer' => [
                'name' => trim($this->newPayerName),
                'address' => trim($this->newPayerAddress),
                'city_state_zip' => trim($this->newPayerCityStateZip),
            ],
            'manual' => true,
        ];

        if ($this->newNotes !== '') {
            $notesPayload['note'] = trim($this->newNotes);
        }

        $jurisdiction = strtoupper((string) $this->project->state);
        $jurisdiction = in_array($jurisdiction, ['CA', 'AZ', 'FL', 'GA', 'IL', 'MS', 'NV', 'TX', 'UT', 'WY'], true)
            ? 'US-' . $jurisdiction
            : 'US-GENERIC';

        // A linked payment must belong to this project; ignore anything else.
        $linkedPayment = $this->newPaymentId
            ? $this->projectPayments->firstWhere('id', $this->newPaymentId)
            : null;

        LienWaiver::create([
            'belongs_to_vendor_id' => $contractor->id,
            'vendor_id' => $contractor->id,
            'project_id' => $this->project->id,
            'check_id' => null,
            'payment_id' => $linkedPayment?->id,
            'type' => $type,
            'status' => LienWaiverStatus::Draft,
            'amount' => $isPaidInFull ? 0 : $this->newAmount,
            'exceptions_amount' => 0,
            'through_date' => $this->newThroughDate,
            'jurisdiction' => $jurisdiction,
            'notes' => json_encode($notesPayload),
            'created_by_user_id' => $user->id,
        ]);

        $this->showCreate = false;
        $this->selectedProjectForCreate = null;
        $this->project = null;
        unset($this->waivers);

        Flux::toast(text: 'Lien waiver created.', variant: 'success');
    }

    public function sendForSignature(int $waiverId): void
    {
        $waiver = LienWaiver::find($waiverId);

        if (! $waiver) {
            return;
        }

        SendLienWaiverSigningRequestJob::dispatch($waiver->id);

        Flux::toast(
            text: 'Lien waiver queued for delivery to ' . ($waiver->vendor?->business_name ?? 'vendor') . '.',
            variant: 'success',
        );

        unset($this->waivers);
    }

    public function cancel(int $waiverId): void
    {
        $waiver = LienWaiver::find($waiverId);

        if (! $waiver || $waiver->isSigned()) {
            return;
        }

        $waiver->forceFill(['status' => LienWaiverStatus::Cancelled])->save();

        Flux::toast(text: 'Lien waiver cancelled.', variant: 'success');

        unset($this->waivers);
    }

    public function delete(int $waiverId): void
    {
        $waiver = LienWaiver::find($waiverId);

        if (! $waiver || $waiver->isSigned()) {
            return;
        }

        $waiver->delete();

        Flux::toast(text: 'Lien waiver deleted.', variant: 'success');

        unset($this->waivers);
    }

    #[Title('Lien Waivers')]
    public function render()
    {
        return view('livewire.lien-waivers.index');
    }

    public function placeholder()
    {
        return view('livewire.lien-waivers.skeleton');
    }
}
