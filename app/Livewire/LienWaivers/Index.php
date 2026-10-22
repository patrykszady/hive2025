<?php

namespace App\Livewire\LienWaivers;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Models\LienWaiver;
use App\Models\SwornStatement;
use App\Models\Project;
use Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Placeholder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use App\Livewire\Concerns\RemembersFilters;

class Index extends Component
{
    use WithPagination, RemembersFilters;

    public ?Project $project = null;

    /**
     * True when the component is embedded on a specific project's page. In that
     * mode the project is fixed and the "select a project" step is skipped — the
     * project must NOT be cleared after creating a waiver (unlike the standalone
     * /lien-waivers page, where clearing it reopens the project selector).
     */
    public bool $scoped = false;

    #[Url(as: 'status')]
    public ?string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    // Manual create form -----------------------------------------------------
    public bool $showCreate = false;

    public bool $showProjectSelector = false;

    public ?int $selectedProjectForCreate = null;

    public string $newType = LienWaiverType::ConditionalProgress->value;

    public ?string $newAmount = null;

    /**
     * Claimant (the party waiving). Defaults to the contractor of record for a
     * GC→owner waiver; a project sub/supplier can be selected to issue a
     * sub→GC waiver instead.
     */
    public ?int $newClaimantVendorId = null;

    public ?string $newThroughDate = null;

    public string $newPayerName = '';

    public string $newPayerAddress = '';

    public string $newPayerCityStateZip = '';

    public string $newNotes = '';

    /** 'client' = bill the project client (default); 'custom' = enter payer manually. */
    public string $payerSource = 'client';

    // Sworn statement (GCSS form) modal --------------------------------------
    public bool $showSwornStatement = false;

    /**
     * Editable per-vendor rows for the sworn statement, prefilled from the
     * project's bids/expenses/checks by SwornStatementGenerator::buildRows().
     *
     * @var array<int, array{vendor_id:int,name:string,include:bool,kind:string,contract:string,paid:float,this_payment:string}>
     */
    public array $ssRows = [];

    /** The draw amount the owner is paying now ("Net amount of this payment"). */
    public ?string $ssThisPayment = null;

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 20;
    }

    /**
     * Column defs — ONE source for the real header row, the cells and the
     * skeleton, so they can never drift (same contract as
     * EmailTrackingTable::columnDefs).
     *
     * scoped = the per-draw table inside a project card: no Project column
     * (it's implied) and no Through date (the draw header carries the date),
     * squeezed into a ~500px column, so widths are explicit percentages.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(bool $scoped = false): array
    {
        if ($scoped) {
            // Width budget: Vendor is the only column that truncates in
            // practice, so it takes the slack; Type/Status hold short badges.
            return [
                ['label' => 'Vendor', 'width' => 'w-[40%] min-w-0', 'skeletonWidth' => 'w-24'],
                ['label' => 'Amount', 'width' => 'w-[22%] min-w-0', 'skeletonWidth' => 'w-16'],
                ['label' => 'Type', 'width' => 'w-[18%] min-w-0', 'skeleton' => 'badge'],
                ['label' => 'Status', 'width' => 'w-[20%] min-w-0', 'skeleton' => 'badge'],
            ];
        }

        // The real table declares no per-column widths — .index-table is
        // table-fixed, so the six columns split evenly. Mirror that exactly.
        return [
            // Project cell stacks the project name over its address, so its rows
            // are two lines (60px) rather than the shared 44px rhythm — the
            // skeleton has to stack too or the card jumps when rows swap in.
            ['label' => 'Project', 'width' => '', 'skeleton' => 'two-line', 'skeletonWidth' => 'w-28', 'skeletonSubWidth' => 'w-20'],
            ['label' => 'Vendor', 'width' => '', 'skeletonWidth' => 'w-24'],
            ['label' => 'Amount', 'width' => '', 'skeletonWidth' => 'w-16'],
            ['label' => 'Through', 'width' => '', 'skeletonWidth' => 'w-12'],
            ['label' => 'Type', 'width' => '', 'skeleton' => 'badge'],
            ['label' => 'Status', 'width' => '', 'skeleton' => 'badge'],
        ];
    }

    #[Placeholder]
    public function skeleton()
    {
        return view('livewire.lien-waivers.skeleton');
    }

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->project = $project;
            $this->scoped = true;
            $this->prefillPayerFromProject();
            $this->payerSource = $project->client ? 'client' : 'custom';
            $this->newThroughDate = now()->toDateString();
        }
    }

    /**
     * Default the payer fields to the project client's billing address,
     * falling back to the project's site address when no client is on file
     * — delegates to App\Services\LienWaivers\LienWaiverPayerDefaults, the
     * same derivation the Hive Contractors app's create screen fetches.
     */
    protected function prefillPayerFromProject(): void
    {
        if (! $this->project) {
            return;
        }

        $defaults = app(\App\Services\LienWaivers\LienWaiverPayerDefaults::class)->forProject($this->project);

        $this->newPayerName = $defaults['name'];
        $this->newPayerAddress = $defaults['address'];
        $this->newPayerCityStateZip = $defaults['city_state_zip'];
    }

    /**
     * When the payer radio flips back to the project client, restore the
     * client's details (in case they were edited under "Someone else").
     */
    public function updatedPayerSource(string $value): void
    {
        if ($value === 'client') {
            $this->prefillPayerFromProject();
        }
    }

    /** The authenticated user's vendor — the contractor of record / GC. */
    #[Computed]
    public function contractorVendor()
    {
        return auth()->user()?->vendor;
    }

    /**
     * Candidate claimants for a sub→GC waiver: vendors who have actual
     * transactions (payments, checks, or expenses) on this project,
     * excluding the GC itself.
     */
    #[Computed]
    public function projectSubVendors()
    {
        $gc = $this->contractorVendor;

        if (! $this->project || ! $gc) {
            return collect();
        }

        $gcId = (int) $gc->id;
        $projectId = (int) $this->project->id;

        // Vendors with payments on this project
        $paymentVendorIds = \App\Models\Payment::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->distinct('belongs_to_vendor_id')
            ->pluck('belongs_to_vendor_id');

        // Vendors with checks on this project
        $checkVendorIds = \App\Models\Check::withoutGlobalScopes()
            ->whereIn('id', function ($q) use ($projectId) {
                $q->select('check_id')
                    ->from('payments')
                    ->where('project_id', $projectId)
                    ->whereNotNull('check_id');
            })
            ->distinct('vendor_id')
            ->pluck('vendor_id');

        // Vendors with expenses on this project, or splits of expenses allocated to it
        $expenseVendorIds = \App\Support\ProjectSupplierSpend::vendorIds($projectId);

        $ids = $paymentVendorIds->merge($checkVendorIds)
            ->merge($expenseVendorIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $gcId)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return \App\Models\Vendor::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'address', 'city', 'state', 'zip_code']);
    }

    /** True when the selected claimant is a sub/supplier (not the GC). */
    #[Computed]
    public function isSubClaimant(): bool
    {
        $gcId = (int) ($this->contractorVendor?->id ?? 0);

        return $this->newClaimantVendorId !== null
            && (int) $this->newClaimantVendorId !== $gcId;
    }

    /** One-line address for the project client, shown on the payer radio card. */
    #[Computed]
    public function payerClientLine(): string
    {
        $client = $this->project?->client;

        if (! $client) {
            return '';
        }

        $cityStateZip = trim(
            trim((string) ($client->city ?? ''))
            . ($client->state ? ', ' . $client->state : '')
            . ' ' . ($client->zip_code ?? '')
        );

        return implode(', ', array_filter([
            trim((string) ($client->address ?? '')),
            $cityStateZip,
        ]));
    }

    #[Computed]
    public function waivers()
    {
        return LienWaiver::query()
            ->with(['vendor', 'payerVendor', 'project', 'check', 'payment'])
            ->when($this->project?->id, fn ($q) => $q->where('project_id', $this->project->id))
            ->when(filled($this->statusFilter), fn ($q) => $q->where('status', $this->statusFilter))
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
        // Two options matching the printed Illinois form titles. Progress and
        // final both carry a dollar amount, so both map to the "conditional"
        // enum values (UnconditionalFinal would force "PAID IN FULL").
        return [
            ['value' => LienWaiverType::ConditionalProgress->value, 'label' => 'Waiver of Lien to Date'],
            ['value' => LienWaiverType::ConditionalFinal->value, 'label' => 'Final Waiver of Lien'],
        ];
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
        $this->newClaimantVendorId = $this->contractorVendor?->id;
        $this->newThroughDate = now()->toDateString();
        $this->prefillPayerFromProject();
        $this->payerSource = $this->project->client ? 'client' : 'custom';
        $this->newNotes = '';
        unset($this->projectPayments, $this->isSubClaimant, $this->claimantTransactions);
        $this->showCreate = true;
    }

    /**
     * Payments already recorded on this project — shown read-only in the create
     * modal for reference (every one is treated as "amount paid" on the
     * affidavit). New payments are recorded from the project's Payments card.
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

    /**
     * Money paid to the selected sub on this project, shown read-only in the
     * create modal. Sub payouts are recorded as expenses (usually check-paid),
     * not as Payment rows — those only track money received from the client.
     */
    #[Computed]
    public function claimantTransactions()
    {
        if (! $this->project || ! $this->isSubClaimant || ! $this->newClaimantVendorId) {
            return collect();
        }

        $vendorId = (int) $this->newClaimantVendorId;
        $projectId = (int) $this->project->id;

        // Expenses on the project and splits of expenses allocated to it.
        $expenses = \App\Support\ProjectSupplierSpend::transactions($projectId, $vendorId);

        $checkPayments = \App\Models\Payment::withoutGlobalScope(\App\Scopes\PaymentScope::class)
            ->where('project_id', $projectId)
            ->whereIn('check_id', function ($q) use ($vendorId) {
                $q->select('id')->from('checks')->where('vendor_id', $vendorId)->whereNull('deleted_at');
            })
            ->get()
            ->map(fn ($p) => (object) [
                'amount' => (float) $p->amount,
                'date' => $p->date,
                'reference' => $p->reference ?: '',
            ]);

        return $expenses->concat($checkPayments)->sortByDesc('date')->values();
    }

    public function updatedNewClaimantVendorId(): void
    {
        unset($this->isSubClaimant, $this->claimantTransactions);
    }

    public function createWaiver(): void
    {
        // Guard against a double-submit (e.g. rapid double-click): once the first
        // request creates the waiver it closes the modal, so a queued second
        // request finds showCreate already false and bails.
        if (! $this->project || ! $this->showCreate) {
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

        // Resolve the claimant. Default is the contractor of record; otherwise a
        // sub/supplier tied to this project.
        $claimantId = (int) ($this->newClaimantVendorId ?: $contractor->id);
        $isSub = $claimantId !== (int) $contractor->id;

        if ($isSub && ! $this->projectSubVendors->contains('id', $claimantId)) {
            Flux::toast(text: 'That vendor is not on this project.', variant: 'danger');
            return;
        }

        // The amount input formats with thousands separators as you type; store
        // the raw number.
        if ($this->newAmount !== null) {
            $this->newAmount = str_replace(',', '', $this->newAmount);
        }

        $rules = [
            'newType' => ['required', Rule::in(array_column(LienWaiverType::cases(), 'value'))],
            'newAmount' => [$isPaidInFull ? 'nullable' : 'required', 'nullable', 'numeric', 'gte:0'],
            'newThroughDate' => ['required', 'date'],
            'newNotes' => ['nullable', 'string', 'max:2000'],
        ];

        // The GC's own waiver names the project client as payer; a sub waiver's
        // payer is the GC itself (from the belongs_to_vendor relation), so no
        // manual payer entry is needed.
        if (! $isSub) {
            $rules['newPayerName'] = ['required', 'string', 'max:255'];
            $rules['newPayerAddress'] = ['nullable', 'string', 'max:255'];
            $rules['newPayerCityStateZip'] = ['nullable', 'string', 'max:255'];
        }

        $this->validate($rules, ['newAmount.gte' => 'Amount cannot be negative.']);

        app(\App\Services\LienWaivers\LienWaiverCreator::class)->create(
            $this->project,
            $contractor,
            $type,
            $claimantId,
            $this->newAmount,
            $this->newThroughDate,
            [
                'name' => $this->newPayerName,
                'address' => $this->newPayerAddress,
                'city_state_zip' => $this->newPayerCityStateZip,
            ],
            $this->newNotes,
            $user->id,
        );

        // Close and clear the form immediately so a stale re-open can't resubmit.
        $this->showCreate = false;
        $this->selectedProjectForCreate = null;
        $this->newAmount = null;
        $this->newNotes = '';

        // Only the standalone page clears the project (so the selector reopens for
        // the next waiver). On a project page we stay in the project's context.
        if (! $this->scoped) {
            $this->project = null;
        }

        unset($this->waivers);

        Flux::toast(text: 'Lien waiver created.', variant: 'success');
    }

    /**
     * Open the Sworn Statement (GCSS) modal with one editable row per vendor
     * who has money on this project. Contract amounts prefill from bids; rows
     * with no recorded bid stay blank for the user to fill in.
     */
    public function openSwornStatement(): void
    {
        if (! $this->project) {
            return;
        }

        $this->authorize('create', LienWaiver::class);

        $this->resetValidation();
        // Leaving this set would turn a "New draw" click into a silent edit of
        // whichever draw was opened last.
        $this->editingStatementId = null;
        $this->ssThisPayment = null;
        $this->ssRows = app(\App\Services\LienWaivers\DrawPackageService::class)
            ->newDrawRows($this->project, $this->contractorVendor);
        unset($this->kindOfWorkOptions);
        $this->showSwornStatement = true;
    }

    /**
     * Draw currently being edited, or null when the modal is creating a new
     * one. Locked: it decides whether generateSwornStatement() creates or
     * updates, so the browser must not be able to set it.
     */
    #[Locked]
    public ?int $editingStatementId = null;

    /**
     * Reopen an existing draw for editing. Amounts and vendors stay adjustable
     * until each waiver comes back signed — one sub signing shouldn't freeze
     * the other four, which is why locking is per waiver and not per draw.
     */
    public function editDraw(int $statementId): void
    {
        if (! $this->project) {
            return;
        }

        $this->authorize('create', LienWaiver::class);

        $tenantId = (int) ($this->contractorVendor?->id ?? 0);

        $statement = \App\Models\SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->find($statementId);

        if (! $statement) {
            return;
        }

        $this->resetValidation();

        // A notarized GCSS swears to specific figures. Once its signed scan is
        // back the draw's numbers are settled — its unsigned waivers can still
        // be sent and signed, they just can't be re-priced underneath it.
        try {
            $draw = app(\App\Services\LienWaivers\DrawPackageService::class)
                ->editableDraw($statement, $this->project, $this->contractorVendor);
        } catch (\App\Exceptions\LienWaivers\DrawAlreadySignedException $e) {
            Flux::toast(
                text: 'Draw ' . $e->drawNumber() . ' has a signed sworn statement and can no longer be edited.',
                variant: 'danger',
            );

            return;
        }

        $this->ssRows = $draw['rows'];
        $this->ssThisPayment = $draw['this_payment'];
        $this->editingStatementId = (int) $statement->id;
        unset($this->kindOfWorkOptions);
        $this->showSwornStatement = true;
    }

    /**
     * Suggestions for the statement's Kind of Work autocomplete: the common
     * trades, plus every kind previously used on this contractor's waivers —
     * same pattern as the estimate payment-milestone descriptions.
     */
    #[Computed]
    public function kindOfWorkOptions(): array
    {
        return app(\App\Services\LienWaivers\DrawPackageService::class)
            ->kindOfWorkOptions($this->contractorVendor);
    }

    /**
     * One-step draw package: render the GC's sworn statement (GCSS) and, for
     * every sub listed on it, create the sub's own Waiver of Lien + affidavit
     * as a draft (skipping any sub that already has an open draft on this
     * project). The subs then appear in the table ready to send or download.
     */
    public function generateSwornStatement()
    {
        if (! $this->project || ! $this->showSwornStatement) {
            return;
        }

        $contractor = $this->contractorVendor;

        if (! $contractor) {
            Flux::toast(text: 'Your account is missing a vendor association.', variant: 'danger');
            return;
        }

        $service = app(\App\Services\LienWaivers\DrawPackageService::class);

        [$this->ssRows, $this->ssThisPayment] = $service->normalize($this->ssRows, $this->ssThisPayment);

        // One validation pass so every problem shows at once — the draw
        // amount, the money fields, and the type of work required for each
        // included vendor (it prints on the statement and the affidavit).
        $validator = $service->buildValidator($this->ssRows, $this->ssThisPayment);

        $this->resetErrorBag();

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        // Editing an existing draw reuses the same write logic and then
        // diverges: it must preserve signed waivers rather than create a
        // second package.
        if ($this->editingStatementId) {
            $statement = \App\Models\SwornStatement::query()
                ->where('belongs_to_vendor_id', $contractor->id)
                ->find($this->editingStatementId);

            if (! $statement) {
                $this->editingStatementId = null;
                Flux::toast(text: 'That draw no longer exists.', variant: 'danger');

                return;
            }

            try {
                $result = $service->updateDraw($statement, $this->project, $contractor, $this->ssRows, (float) $this->ssThisPayment, auth()->user());
            } catch (\App\Exceptions\LienWaivers\DrawAlreadySignedException $e) {
                Flux::toast(
                    text: 'Draw ' . $e->drawNumber() . ' was signed while you were editing — no changes saved.',
                    variant: 'danger',
                );
                $this->showSwornStatement = false;
                $this->editingStatementId = null;

                return;
            }

            $this->showSwornStatement = false;
            $this->editingStatementId = null;
            unset($this->waivers, $this->swornStatements, $this->drawGroups);

            $parts = [];
            if ($result['revised'] > 0) {
                $parts[] = $result['revised'] . ' waiver' . ($result['revised'] === 1 ? '' : 's') . ' revised and re-sent';
            }
            if (($result['added_sent'] ?? 0) > 0) {
                $parts[] = $result['added_sent'] . ' added';
            }
            if (($result['added_printed'] ?? 0) > 0) {
                $parts[] = $result['added_printed'] . ' added and emailed to you to forward';
            }
            if (($result['added_reused'] ?? 0) > 0) {
                $parts[] = $result['added_reused'] . ' carried over from a prior signed waiver';
            }
            if ($result['removed'] > 0) {
                $parts[] = $result['removed'] . ' removed';
            }
            if ($result['frozen'] > 0) {
                $parts[] = $result['frozen'] . ' signed waiver' . ($result['frozen'] === 1 ? '' : 's') . ' left untouched';
            }

            Flux::toast(
                text: 'Draw ' . $result['statement']->drawNumber() . ' updated' . ($parts ? ' — ' . implode(', ', $parts) . '.' : '.'),
                variant: 'success',
            );

            return;
        }

        $result = $service->createDraw($this->project, $contractor, $this->ssRows, (float) $this->ssThisPayment, auth()->user());

        $this->showSwornStatement = false;
        unset($this->waivers, $this->swornStatements);

        $parts = [];
        if ($result['gc_waiver_created']) {
            $parts[] = 'GC waiver created';
        }
        if ($result['sub_sent'] > 0) {
            $parts[] = $result['sub_sent'] . ' sub waiver' . ($result['sub_sent'] === 1 ? '' : 's') . ' sent for signature';
        }
        if ($result['sub_printed'] > 0) {
            $parts[] = $result['sub_printed'] . ' waiver' . ($result['sub_printed'] === 1 ? '' : 's') . ' emailed to you to forward (no vendor email on file)';
        }
        if (($result['sub_reused'] ?? 0) > 0) {
            $parts[] = $result['sub_reused'] . ' signed waiver' . ($result['sub_reused'] === 1 ? '' : 's') . ' carried over from the last draw';
        }
        if ($result['saved_bids'] > 0) {
            $parts[] = $result['saved_bids'] . ' contract amount' . ($result['saved_bids'] === 1 ? '' : 's') . ' saved as bids';
        }
        if ($result['emailed_producer']) {
            $parts[] = 'package emailed to you';
        }

        // Waiver requests to subs wait for the GC's business hours; say so,
        // otherwise "sent for signature" reads as already-in-their-inbox.
        $waiverSendAt = $result['send_at'];
        if ($waiverSendAt->isFuture() && (($result['sub_sent'] ?? 0) + ($result['sub_printed'] ?? 0)) > 0) {
            $parts[] = 'signature requests send ' . $waiverSendAt->diffForHumans() . ' (' . $waiverSendAt->format('D g:i A') . '), during business hours';
        }

        Flux::toast(
            text: 'Sworn statement generated' . ($parts ? ' — ' . implode(', ', $parts) . '.' : '.'),
            variant: 'success',
        );

        return response()->streamDownload(
            fn () => print($result['download_binary']),
            $result['download_filename'],
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * True once the project has a signed contract — the base (type 1) bid is
     * created at estimate acceptance. Lien waivers stay hidden on the project
     * card until then, which also guarantees every waiver has an estimate and
     * client behind it.
     */
    #[Computed]
    public function hasSignedContract(): bool
    {
        if (! $this->project) {
            return false;
        }

        return app(\App\Services\LienWaivers\DrawPackageService::class)
            ->hasSignedContract($this->project, $this->contractorVendor);
    }

    /**
     * The project's waivers grouped per draw (scoped card): each stored
     * statement with the waivers stamped to it, newest draw first, plus any
     * waivers not tied to a draw (manual ones).
     *
     * @return array{draws: \Illuminate\Support\Collection, other: \Illuminate\Support\Collection}
     */
    #[Computed]
    public function drawGroups(): array
    {
        if (! $this->project) {
            return ['draws' => collect(), 'other' => collect()];
        }

        return app(\App\Services\LienWaivers\DrawPackageService::class)
            ->drawGroups($this->project, $this->contractorVendor);
    }

    /**
     * Waiver ids whose signing-request email has an "opened" tracking event —
     * open/click webhooks link back to the sent record, and the sent record's
     * metadata carries the lien_waiver_id.
     */
    #[Computed]
    public function openedWaiverIds(): \Illuminate\Support\Collection
    {
        if (! $this->scoped || ! $this->project) {
            return collect();
        }

        $sent = \App\Models\EmailTracking::query()
            ->where('event_type', 'sent')
            ->where('project_id', $this->project->id)
            ->whereNotNull('metadata->lien_waiver_id')
            ->get(['id', 'message_id', 'metadata']);

        if ($sent->isEmpty()) {
            return collect();
        }

        $opened = \App\Models\EmailTracking::query()
            ->where('event_type', 'opened')
            ->where(function ($query) use ($sent) {
                $query->whereIn('metadata->linked_sent_id', $sent->pluck('id')->all())
                    ->orWhereIn('message_id', $sent->pluck('message_id')->filter()->all());
            })
            ->get(['message_id', 'metadata']);

        $openedSentIds = $opened->map(fn ($event) => (int) data_get($event->metadata, 'linked_sent_id'))->filter();
        $openedMessageIds = $opened->pluck('message_id')->filter();

        return $sent
            ->filter(fn ($event) => $openedSentIds->contains($event->id) || $openedMessageIds->contains($event->message_id))
            ->map(fn ($event) => (int) data_get($event->metadata, 'lien_waiver_id'))
            ->filter()
            ->unique()
            ->values();
    }

    /** Stored GCSS statements for this project (or the tenant, standalone). */
    #[Computed]
    public function swornStatements()
    {
        return app(\App\Services\LienWaivers\DrawPackageService::class)
            ->swornStatements($this->contractorVendor, $this->project);
    }

    /** Draw pending delete confirmation (drives the confirm modal). */
    #[Locked]
    public ?int $deletingDrawId = null;

    public bool $showDrawDelete = false;

    public function confirmDeleteDraw(int $statementId): void
    {
        $this->deletingDrawId = $statementId;
        $this->showDrawDelete = true;
        unset($this->deletingDraw);
    }

    /**
     * What the pending delete will actually remove — shown in the modal so
     * the user knows exactly what happens before confirming.
     *
     * @return array{statement: \App\Models\SwornStatement, number: int, total: int, sent: int, signed: int}|null
     */
    #[Computed]
    public function deletingDraw(): ?array
    {
        if (! $this->deletingDrawId) {
            return null;
        }

        return app(\App\Services\LienWaivers\DrawPackageService::class)
            ->deletingDrawSummary($this->deletingDrawId, $this->contractorVendor);
    }

    public function deleteConfirmedDraw(): void
    {
        if ($this->deletingDrawId) {
            $this->deleteSwornStatement($this->deletingDrawId);
        }

        $this->showDrawDelete = false;
        $this->deletingDrawId = null;
        unset($this->deletingDraw);
    }

    public function deleteSwornStatement(int $statementId): void
    {
        $statement = \App\Models\SwornStatement::query()
            ->where('belongs_to_vendor_id', (int) ($this->contractorVendor?->id ?? 0))
            ->find($statementId);

        if (! $statement) {
            return;
        }

        // Deleting a draw removes the whole package: the GCSS and every waiver
        // stamped with it. Everything is soft-deleted (recoverable, stops
        // blocking dedup on the next draw) — the stored PDFs stay on disk.
        app(\App\Services\LienWaivers\DrawPackageService::class)
            ->deleteDraw($statement, $this->contractorVendor);

        unset($this->swornStatements, $this->waivers, $this->drawGroups);

        Flux::toast(text: 'Draw deleted.', variant: 'success');
    }

    /**
     * When a waiver email should actually leave. Subs get lien waiver
     * requests during the GC's working day — a request made at 9 PM
     * shouldn't put a signature request in a sub's inbox overnight. Inside
     * business hours this is "now", so the normal path is unchanged.
     */
    protected function waiverSendAt($contractor = null): \Carbon\Carbon
    {
        $vendor = $contractor ?: $this->contractorVendor ?: auth()->user()?->vendor;

        return $vendor
            ? $vendor->nextBusinessHoursOpening()
            : now();
    }

    public function sendForSignature(int $waiverId): void
    {
        // LienWaiverScope matches EITHER party (issuer or recipient) — these
        // are writes only the issuing (owning) vendor may make, so the
        // recipient sub must be excluded explicitly.
        $waiver = LienWaiver::where('belongs_to_vendor_id', auth()->user()->vendor->id)->find($waiverId);

        // Only open waivers can be (re)sent — a signed or cancelled document
        // must never regress to Sent from a stale table.
        if (! $waiver || $waiver->isSigned() || $waiver->status === LienWaiverStatus::Cancelled) {
            return;
        }

        $sendAt = $this->waiverSendAt();
        SendLienWaiverSigningRequestJob::dispatch($waiver->id)->delay($sendAt);

        Flux::toast(
            text: $sendAt->isFuture()
                ? 'Lien waiver queued for ' . ($waiver->vendor?->business_name ?? 'vendor') . ' — sending ' . $sendAt->diffForHumans() . ' (' . $sendAt->format('D g:i A') . '), during business hours.'
                : 'Lien waiver queued for delivery to ' . ($waiver->vendor?->business_name ?? 'vendor') . '.',
            variant: 'success',
        );

        unset($this->waivers);
    }

    public function cancel(int $waiverId): void
    {
        // LienWaiverScope matches EITHER party — only the issuing vendor may
        // cancel its own waiver, never the recipient sub.
        $waiver = LienWaiver::where('belongs_to_vendor_id', auth()->user()->vendor->id)->find($waiverId);

        if (! $waiver || $waiver->isSigned()) {
            return;
        }

        $waiver->forceFill(['status' => LienWaiverStatus::Cancelled])->save();

        Flux::toast(text: 'Lien waiver cancelled.', variant: 'success');

        unset($this->waivers);
    }

    public function delete(int $waiverId): void
    {
        // LienWaiverScope matches EITHER party — only the issuing vendor may
        // delete its own waiver, never the recipient sub.
        $waiver = LienWaiver::where('belongs_to_vendor_id', auth()->user()->vendor->id)->find($waiverId);

        if (! $waiver || $waiver->isSigned()) {
            return;
        }

        $waiver->delete();

        Flux::toast(text: 'Lien waiver deleted.', variant: 'success');

        unset($this->waivers);
    }

    /**
     * The filters this page keeps between visits (see RemembersFilters).
     * None when a project page embeds this list (scoped).
     *
     * @return list<string>
     */
    protected function rememberedFilters(): array
    {
        return $this->scoped ? [] : ['statusFilter', 'search'];
    }

    #[Title('Lien Waivers')]
    public function render()
    {
        return view('livewire.lien-waivers.index');
    }

    public function placeholder(array $params = [])
    {
        // The card renders header-only (and no accordion) when the project has
        // no waivers — the skeleton has to know that too, or it paints a
        // chevron and rows that vanish a moment later. A COUNT is far cheaper
        // than the grouped query the skeleton stands in for.
        $project = $params['project'] ?? null;
        $projectId = $project instanceof Project
            ? $project->id
            : (is_numeric($project) ? (int) $project : null);

        $hasWaivers = $projectId
            ? LienWaiver::withoutGlobalScopes()->where('project_id', $projectId)->exists()
                || SwornStatement::withoutGlobalScopes()->where('project_id', $projectId)->exists()
            : true;

        return view('livewire.lien-waivers.skeleton', ['hasWaivers' => $hasWaivers]);
    }
}
