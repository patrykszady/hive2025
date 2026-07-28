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

        // Vendors with expenses on this project
        $expenseVendorIds = \App\Models\Expense::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->distinct('vendor_id')
            ->pluck('vendor_id');

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

        $expenses = \App\Models\Expense::withoutGlobalScope(\App\Scopes\ExpenseScope::class)
            ->with(['check' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('project_id', $projectId)
            ->where('vendor_id', $vendorId)
            ->get()
            ->map(fn ($e) => (object) [
                'amount' => (float) $e->amount,
                'date' => $e->date,
                'reference' => $e->check
                    ? trim(($e->check->check_type ?? 'Check') . ($e->check->check_number ? ' #' . $e->check->check_number : ''))
                    : ($e->invoice ?: ($e->paid_by ?: '')),
            ]);

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

        $notesPayload = ['manual' => true];

        if (! $isSub) {
            $notesPayload['payer'] = [
                'name' => trim($this->newPayerName),
                'address' => trim($this->newPayerAddress),
                'city_state_zip' => trim($this->newPayerCityStateZip),
            ];
        }

        if ($this->newNotes !== '') {
            $notesPayload['note'] = trim($this->newNotes);
        }

        $jurisdiction = $this->projectJurisdiction();

        LienWaiver::create([
            // Payer is always the contractor of record; claimant is the GC (own
            // waiver) or the selected sub/supplier.
            'belongs_to_vendor_id' => $contractor->id,
            'vendor_id' => $claimantId,
            'project_id' => $this->project->id,
            'check_id' => null,
            'payment_id' => null,
            'type' => $type,
            'status' => LienWaiverStatus::Draft,
            'amount' => $isPaidInFull ? 0 : $this->newAmount,
            'exceptions_amount' => 0,
            'through_date' => $this->newThroughDate,
            'jurisdiction' => $jurisdiction,
            'notes' => json_encode($notesPayload),
            'created_by_user_id' => $user->id,
        ]);

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
        $this->ssThisPayment = null;
        $this->ssRows = \App\Support\SwornStatementGenerator::buildRows($this->project, $this->contractorVendor);
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
        $defaults = [
            'Carpentry', 'Plumbing', 'Electrical', 'HVAC', 'Roofing', 'Drywall',
            'Painting', 'Flooring', 'Tile', 'Masonry', 'Concrete', 'Excavation',
            'Demolition', 'Insulation', 'Windows & doors', 'Landscaping',
            'Materials', 'Equipment rental', 'Debris removal',
        ];

        $tenantId = (int) ($this->contractorVendor?->id ?? 0);

        // Saved work types (the table is the source of record going forward)…
        $saved = \App\Models\WorkType::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->pluck('name');

        // …plus kinds used on past waivers before the table existed.
        $used = LienWaiver::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $tenantId)
            ->whereNotNull('notes')
            ->pluck('notes')
            ->map(fn ($notes) => trim((string) (json_decode((string) $notes, true)['kind_of_work'] ?? '')))
            ->filter();

        $unique = [];
        foreach ($saved->merge($used)->merge($defaults) as $kind) {
            $key = mb_strtolower($kind);
            if (! array_key_exists($key, $unique)) {
                $unique[$key] = $kind;
            }
        }

        return collect(array_values($unique))
            ->sort(fn (string $a, string $b) => strcasecmp($a, $b))
            ->values()
            ->all();
    }

    /** Map the project's state to a lien-waiver jurisdiction code. */
    protected function projectJurisdiction(): string
    {
        $state = strtoupper((string) $this->project->state);

        return in_array($state, ['CA', 'AZ', 'FL', 'GA', 'IL', 'MS', 'NV', 'TX', 'UT', 'WY'], true)
            ? 'US-' . $state
            : 'US-GENERIC';
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

        // Money inputs format with thousands separators as you type.
        $this->ssThisPayment = $this->ssThisPayment !== null
            ? str_replace(',', '', $this->ssThisPayment)
            : null;

        foreach ($this->ssRows as $i => $row) {
            foreach (['contract', 'this_payment'] as $field) {
                if (($row[$field] ?? '') !== '') {
                    $this->ssRows[$i][$field] = str_replace(',', '', (string) $row[$field]);
                }
            }
        }

        // One validation pass so every problem shows at once — the draw
        // amount, the money fields, and the type of work required for each
        // included vendor (it prints on the statement and the affidavit).
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['ssThisPayment' => $this->ssThisPayment, 'ssRows' => $this->ssRows],
            [
                'ssThisPayment' => ['required', 'numeric', 'gte:0.01'],
                'ssRows.*.kind' => ['nullable', 'string', 'max:120'],
                // bids.amount is decimal(8,2) — cap so a saved bid can't overflow.
                'ssRows.*.contract' => ['nullable', 'numeric', 'gte:0', 'lte:999999.99'],
                'ssRows.*.this_payment' => ['nullable', 'numeric', 'gte:0'],
            ],
            [
                'ssThisPayment.required' => 'Enter the amount the owner is paying with this draw.',
                'ssThisPayment.gte' => 'The draw amount must be at least $0.01.',
            ],
        );

        $validator->after(function ($v) {
            foreach ($this->ssRows as $i => $row) {
                if (! empty($row['include']) && trim((string) ($row['kind'] ?? '')) === '') {
                    $v->errors()->add("ssRows.{$i}.kind", 'Select the type of work.');
                }
            }
        });

        $this->resetErrorBag();

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        // Contract amounts typed here for vendors with no bid on the project are
        // saved as their bid, so the next statement prefills itself. Existing
        // bids are never overwritten (they may be structured base + extras).
        $savedBids = 0;

        foreach ($this->ssRows as $row) {
            $contract = (string) ($row['contract'] ?? '');

            if (empty($row['include']) || $contract === '' || (float) $contract <= 0) {
                continue;
            }

            $hasBid = \App\Models\Bid::withoutGlobalScopes()
                ->where('project_id', $this->project->id)
                ->where('vendor_id', (int) $row['vendor_id'])
                ->exists();

            if (! $hasBid) {
                \App\Models\Bid::withoutGlobalScopes()->create([
                    'project_id' => $this->project->id,
                    'vendor_id' => (int) $row['vendor_id'],
                    'amount' => (float) $contract,
                    'type' => 1,
                ]);
                $savedBids++;
            }
        }

        // Create the full draw package: the GC's own waiver+affidavit for this
        // Selected kinds of work become WorkType records and each vendor's
        // default, so the next statement (and the planner/tasks) prefill them.
        $this->saveWorkTypesFromStatement($contractor);

        // The statement record anchors the draw: waivers created below are
        // stamped with its id so the card groups per draw and the package can
        // be downloaded per statement.
        $statement = \App\Models\SwornStatement::create([
            'project_id' => $this->project->id,
            'belongs_to_vendor_id' => $contractor->id,
            'this_payment' => (float) $this->ssThisPayment,
            'created_by_user_id' => auth()->id(),
        ]);

        // Create the draw package: the GC's own waiver+affidavit for this
        // draw, plus a waiver for every sub on the statement.
        $createdGcWaiver = $this->createGcWaiverForStatement($contractor, $statement->id);
        $subWaivers = $this->createSubWaiversForStatement($contractor, $statement->id);

        $doc = \App\Support\SwornStatementGenerator::generate(
            $this->project,
            $contractor,
            $this->ssRows,
            (float) $this->ssThisPayment,
            $statement->drawNumber(),
            $statement->id,
        );

        $relativePath = sprintf('sworn-statements/%d/%d/%s', $this->project->id, $statement->id, $doc['filename']);
        \Illuminate\Support\Facades\Storage::disk('files')->put($relativePath, $doc['binary']);
        $statement->forceFill(['filename' => $doc['filename'], 'path' => $relativePath])->save();

        // The immediate download bundles the GCSS with the GC's own waiver.
        $downloadBinary = $doc['binary'];
        $downloadName = $doc['filename'];
        $gcWaiver = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('vendor_id', $contractor->id)
            ->whereNull('deleted_at')
            ->latest('id')
            ->first();

        if ($gcWaiver) {
            try {
                $gcDoc = \App\Support\LienWaiverDocumentGenerator::generate($gcWaiver);
                $merged = \App\Support\PdfMerger::merge([$doc['binary'], $gcDoc['binary']]);

                if ($merged !== null && $merged !== $doc['binary']) {
                    $downloadBinary = $merged;
                    $downloadName = \App\Mail\DrawPackageMail::packageFilenameFor($statement);
                }
            } catch (\Throwable) {
                // GC waiver render failed — the GCSS alone still downloads.
            }
        }

        // The producer also gets the package by email for their records; the
        // job flips the statement + GC waiver to Sent after the actual send.
        $producer = auth()->user();
        $emailedProducer = false;
        if ($producer && ! empty($producer->email)) {
            \App\Jobs\SendDrawPackageJob::dispatch($statement->id, $producer->id);
            $emailedProducer = true;
        }

        $this->showSwornStatement = false;
        unset($this->waivers, $this->swornStatements);

        $parts = [];
        if ($createdGcWaiver) {
            $parts[] = 'GC waiver created';
        }
        if ($subWaivers['sent'] > 0) {
            $parts[] = $subWaivers['sent'] . ' sub waiver' . ($subWaivers['sent'] === 1 ? '' : 's') . ' sent for signature';
        }
        if ($subWaivers['printed'] > 0) {
            $parts[] = $subWaivers['printed'] . ' waiver' . ($subWaivers['printed'] === 1 ? '' : 's') . ' emailed to you to forward (no vendor email on file)';
        }
        if ($savedBids > 0) {
            $parts[] = $savedBids . ' contract amount' . ($savedBids === 1 ? '' : 's') . ' saved as bids';
        }
        if ($emailedProducer) {
            $parts[] = 'package emailed to you';
        }

        Flux::toast(
            text: 'Sworn statement generated' . ($parts ? ' — ' . implode(', ', $parts) . '.' : '.'),
            variant: 'success',
        );

        return response()->streamDownload(
            fn () => print($downloadBinary),
            $downloadName,
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
        if (! $this->project || ! $this->contractorVendor) {
            return false;
        }

        return \App\Models\Bid::withoutGlobalScopes()
            ->where('project_id', $this->project->id)
            ->where('vendor_id', $this->contractorVendor->id)
            ->where('type', 1)
            ->exists();
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
        $tenantId = (int) ($this->contractorVendor?->id ?? 0);

        if (! $tenantId || ! $this->project) {
            return ['draws' => collect(), 'other' => collect()];
        }

        $statements = \App\Models\SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->where('project_id', $this->project->id)
            ->orderByDesc('created_at')
            ->get();

        $waivers = LienWaiver::query()
            ->where('project_id', $this->project->id)
            ->with(['vendor' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByRaw('vendor_id = ? DESC', [$tenantId])
            ->orderBy('vendor_id')
            ->get();

        $byStatement = $waivers->groupBy(fn ($w) => (int) ($w->sworn_statement_id ?? 0));
        $statementIds = $statements->pluck('id')->map(fn ($id) => (int) $id)->all();

        $draws = $statements->map(fn ($statement) => [
            'statement' => $statement,
            'waivers' => $byStatement->get((int) $statement->id, collect()),
        ]);

        $other = $waivers
            ->filter(fn ($w) => ! in_array((int) ($w->sworn_statement_id ?? 0), $statementIds, true))
            ->values();

        return ['draws' => $draws, 'other' => $other];
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
        $tenantId = (int) ($this->contractorVendor?->id ?? 0);

        if (! $tenantId) {
            return collect();
        }

        return \App\Models\SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->when($this->project, fn ($q) => $q->where('project_id', $this->project->id))
            ->with('project:id,project_name,address')
            ->orderByDesc('created_at')
            ->get();
    }

    /** Draw pending delete confirmation (drives the confirm modal). */
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

        $statement = \App\Models\SwornStatement::query()
            ->where('belongs_to_vendor_id', (int) ($this->contractorVendor?->id ?? 0))
            ->find($this->deletingDrawId);

        if (! $statement) {
            return null;
        }

        $waivers = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('belongs_to_vendor_id', (int) ($this->contractorVendor?->id ?? 0))
            ->whereNull('deleted_at')
            ->get(['id', 'status']);

        return [
            'statement' => $statement,
            'number' => $statement->drawNumber(),
            'total' => $waivers->count(),
            'sent' => $waivers->where('status', LienWaiverStatus::Sent)->count(),
            'signed' => $waivers->where('status', LienWaiverStatus::Signed)->count(),
        ];
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
        LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('belongs_to_vendor_id', (int) ($this->contractorVendor?->id ?? 0))
            ->whereNull('deleted_at')
            ->get()
            ->each
            ->delete();

        $statement->delete();
        unset($this->swornStatements, $this->waivers, $this->drawGroups);

        Flux::toast(text: 'Draw deleted.', variant: 'success');
    }

    /**
     * Persist each included row's Kind of Work: find-or-create the WorkType
     * (per tenant, case-insensitive) and set it as the vendor's default so
     * future statements, planner entries and tasks prefill it.
     */
    protected function saveWorkTypesFromStatement($contractor): void
    {
        foreach ($this->ssRows as $row) {
            $kind = trim((string) ($row['kind'] ?? ''));
            $vendorId = (int) ($row['vendor_id'] ?? 0);

            if (empty($row['include']) || $kind === '' || $vendorId === 0) {
                continue;
            }

            $workType = \App\Models\WorkType::resolve((int) $contractor->id, $kind);

            if (! $workType) {
                continue;
            }

            \App\Models\Vendor::withoutGlobalScopes()
                ->whereKey($vendorId)
                ->where(fn ($q) => $q->whereNull('work_type_id')->orWhere('work_type_id', '!=', $workType->id))
                ->update(['work_type_id' => $workType->id]);
        }
    }

    /**
     * Create the GC's own waiver+affidavit for the draw the statement covers —
     * claimant and payer-of-record are both the GC, the payer on the document
     * is the project client, amount is "Net amount of this payment". Skipped
     * when the draw is zero or the GC already has an open waiver.
     */
    protected function createGcWaiverForStatement($contractor, int $statementId): bool
    {
        $amount = (float) $this->ssThisPayment;

        if ($amount <= 0) {
            return false;
        }

        $hasOpen = LienWaiver::withoutGlobalScopes()
            ->where('project_id', $this->project->id)
            ->where('vendor_id', $contractor->id)
            ->where('belongs_to_vendor_id', $contractor->id)
            ->whereNull('deleted_at')
            ->whereIn('status', [LienWaiverStatus::Draft, LienWaiverStatus::Sent])
            ->exists();

        if ($hasOpen) {
            // Re-stamp the lingering GC draft onto this draw so grouping and
            // the per-draw download include it.
            LienWaiver::withoutGlobalScopes()
                ->where('project_id', $this->project->id)
                ->where('vendor_id', $contractor->id)
                ->where('belongs_to_vendor_id', $contractor->id)
                ->whereNull('deleted_at')
                ->whereIn('status', [LienWaiverStatus::Draft, LienWaiverStatus::Sent])
                ->update(['sworn_statement_id' => $statementId]);

            return false;
        }

        // ClientScope limits clients to ones linked to the auth vendor — load
        // directly so the payer block always fills in.
        $client = $this->project->client()->withoutGlobalScopes()->first();
        $payerCityStateZip = trim(sprintf(
            '%s%s %s',
            $client?->city ?? $this->project->city ?? '',
            ($client?->state ?? $this->project->state)
                ? ', ' . ($client?->state ?? $this->project->state)
                : '',
            $client?->zip_code ?? $this->project->zip_code ?? '',
        ));

        LienWaiver::create([
            'belongs_to_vendor_id' => $contractor->id,
            'vendor_id' => $contractor->id,
            'project_id' => $this->project->id,
            'sworn_statement_id' => $statementId,
            'check_id' => null,
            'payment_id' => null,
            'type' => LienWaiverType::ConditionalProgress,
            'status' => LienWaiverStatus::Draft,
            'amount' => $amount,
            'exceptions_amount' => 0,
            'through_date' => now()->toDateString(),
            'jurisdiction' => $this->projectJurisdiction(),
            'notes' => json_encode([
                'manual' => true,
                'source' => 'sworn_statement',
                'payer' => [
                    'name' => (string) ($client?->name ?? ''),
                    'address' => trim((string) ($client?->address ?? $this->project->address ?? '')),
                    'city_state_zip' => $payerCityStateZip,
                ],
            ]),
            'created_by_user_id' => auth()->id(),
        ]);

        return true;
    }

    /**
     * Create a sub Waiver of Lien (to date) for each included row on the sworn
     * statement, unless that sub already has an open (draft/sent) waiver on the
     * project. Amount is money received to date. Waivers are emailed only when
     * the vendor has an email on file (retail/material suppliers usually
     * don't) — the rest stay as drafts to print and hand over.
     *
     * @return array{sent:int, printed:int}
     */
    protected function createSubWaiversForStatement($contractor, int $statementId): array
    {
        $jurisdiction = $this->projectJurisdiction();
        $throughDate = now()->toDateString();
        $sent = 0;
        $printed = 0;

        // Which row vendors have a deliverable email: a business email, or an
        // employed user with one (mirrors the send job's resolution).
        $rowVendorIds = collect($this->ssRows)->pluck('vendor_id')->filter()->map(fn ($id) => (int) $id);
        $withBusinessEmail = \App\Models\Vendor::withoutGlobalScopes()
            ->whereIn('id', $rowVendorIds)
            ->whereNotNull('business_email')
            ->where('business_email', '!=', '')
            ->pluck('id');
        $withUserEmail = \App\Models\UserVendor::query()
            ->whereIn('vendor_id', $rowVendorIds)
            ->where('is_employed', true)
            ->whereIn('user_id', fn ($q) => $q->select('id')->from('users')->whereNotNull('email'))
            ->pluck('vendor_id');
        $emailableVendorIds = $withBusinessEmail->merge($withUserEmail)->map(fn ($id) => (int) $id)->unique();

        foreach ($this->ssRows as $row) {
            if (empty($row['include'])) {
                continue;
            }

            $vendorId = (int) ($row['vendor_id'] ?? 0);

            // Never issue a sub waiver to the GC itself.
            if ($vendorId === 0 || $vendorId === (int) $contractor->id) {
                continue;
            }

            // Retail vendors (rentals, haulers, incidentals) appear on the
            // sworn statement but don't get waivers — EXCEPT material
            // suppliers (sheets_type "Materials"), who can lien for materials
            // furnished and whose waiver-only document title companies expect.
            if (! empty($row['is_retail']) && empty($row['is_material'])) {
                continue;
            }

            // Money received to date = discovered paid + any amount entered for
            // this draw. That's the "to date" consideration for the waiver.
            $paid = (float) ($row['paid'] ?? 0);
            $thisPayment = ($row['this_payment'] ?? '') !== ''
                ? (float) str_replace(',', '', (string) $row['this_payment'])
                : 0.0;
            $amount = $paid + $thisPayment;

            if ($amount <= 0) {
                continue;
            }

            // Subs with an open waiver on this project don't get a duplicate.
            // A lingering Draft still goes out for signature (no waiver is
            // left unsent); already-Sent ones aren't re-sent. Trashed waivers
            // never block (withoutGlobalScopes drops SoftDeletes too), and the
            // check is tenant-scoped so another GC's waiver can't interfere.
            $openWaiver = LienWaiver::withoutGlobalScopes()
                ->where('project_id', $this->project->id)
                ->where('vendor_id', $vendorId)
                ->where('belongs_to_vendor_id', $contractor->id)
                ->whereNull('deleted_at')
                ->whereIn('status', [LienWaiverStatus::Draft, LienWaiverStatus::Sent])
                ->orderByDesc('id')
                ->first();

            // The statement's Kind of Work rides along so the waiver's
            // affidavit prints the same "What For" as the GCSS row. Retail
            // vendors print the kind as typed, without the labor & material
            // suffix (they supply goods/services, not trade packages).
            $notes = ['manual' => true, 'source' => 'sworn_statement'];
            $kind = trim((string) ($row['kind'] ?? ''));
            if ($kind !== '') {
                $notes['kind_of_work'] = $kind;
            }
            if (! empty($row['is_retail'])) {
                $notes['retail'] = true;
            }

            $canEmail = $emailableVendorIds->contains($vendorId);

            // Automatic finality: a REAL contract (typed or from a bid) that is
            // fully covered by the money received means the sub's work is
            // complete → final waiver (PAID IN FULL). No recorded contract
            // (money-paid fallback) means still running → waiver to date with
            // OPEN markers. A real contract with a balance → plain waiver to
            // date.
            $typedContract = ($row['contract'] ?? '') !== '' ? (float) $row['contract'] : null;
            $isFinal = $typedContract !== null && $amount + 0.009 >= $typedContract;

            if ($openWaiver) {
                if ($openWaiver->status === LienWaiverStatus::Draft) {
                    // Sync this run's row data (amount, kind, type) onto the
                    // lingering draft so the re-sent document reflects the
                    // statement it accompanies.
                    $existingNotes = json_decode((string) $openWaiver->notes, true) ?: [];
                    unset($existingNotes['kind_of_work'], $existingNotes['open'], $existingNotes['retail']);

                    $openWaiver->forceFill([
                        'type' => $isFinal ? LienWaiverType::UnconditionalFinal : LienWaiverType::ConditionalProgress,
                        'sworn_statement_id' => $statementId,
                        'amount' => $amount,
                        'through_date' => $throughDate,
                        'notes' => json_encode(array_merge($existingNotes, $notes)),
                    ])->save();

                    // No vendor email → the job mails the draw's creator with
                    // a "please forward" banner instead of skipping.
                    SendLienWaiverSigningRequestJob::dispatch($openWaiver->id);
                    if ($canEmail) {
                        $sent++;
                    } else {
                        $printed++;
                    }
                }

                continue;
            }

            $waiver = LienWaiver::create([
                'belongs_to_vendor_id' => $contractor->id,
                'vendor_id' => $vendorId,
                'project_id' => $this->project->id,
                'sworn_statement_id' => $statementId,
                'check_id' => null,
                'payment_id' => null,
                'type' => $isFinal ? LienWaiverType::UnconditionalFinal : LienWaiverType::ConditionalProgress,
                'status' => LienWaiverStatus::Draft,
                'amount' => $amount,
                'exceptions_amount' => 0,
                'through_date' => $throughDate,
                'jurisdiction' => $jurisdiction,
                'notes' => json_encode($notes),
                'created_by_user_id' => auth()->id(),
            ]);

            // Every waiver goes out by email: straight to the vendor when they
            // have an address on file (dev delivery is redirected to the dev
            // inbox by Mail::alwaysTo), otherwise to the GC user creating the
            // draw with a "please forward to {vendor}" banner — typical for
            // material suppliers like Home Depot.
            SendLienWaiverSigningRequestJob::dispatch($waiver->id);
            if ($canEmail) {
                $sent++;
            } else {
                $printed++;
            }
        }

        return ['sent' => $sent, 'printed' => $printed];
    }

    public function sendForSignature(int $waiverId): void
    {
        $waiver = LienWaiver::find($waiverId);

        // Only open waivers can be (re)sent — a signed or cancelled document
        // must never regress to Sent from a stale table.
        if (! $waiver || $waiver->isSigned() || $waiver->status === LienWaiverStatus::Cancelled) {
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
