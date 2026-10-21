<?php

namespace App\Services\LienWaivers;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Exceptions\LienWaivers\DrawAlreadySignedException;
use App\Jobs\SendDrawPackageJob;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Mail\DrawPackageMail;
use App\Models\Bid;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\SwornStatement;
use App\Models\User;
use App\Models\UserVendor;
use App\Models\Vendor;
use App\Models\WorkType;
use App\Support\LienWaiverDocumentGenerator;
use App\Support\PdfMerger;
use App\Support\SwornStatementGenerator;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * The GCSS sworn statement / draw package flow — one draw's statement
 * (every sub/supplier paid on the project, footed against the contract)
 * plus the Waiver of Lien + affidavit it creates for each of them.
 *
 * Factored out of App\Livewire\LienWaivers\Index so the web component and
 * the Hive Contractors app's API controller build the EXACT same records,
 * documents and emails — the component stays the only place that owns
 * Livewire state (ssRows, showSwornStatement, …) and now delegates every
 * write to the methods here. Every public method takes the project/vendor/
 * rows explicitly rather than reading component properties, so it has no
 * Livewire dependency at all.
 */
class DrawPackageService
{
    /**
     * Rows for a brand-new draw: one editable row per vendor with money on
     * the project (SwornStatementGenerator::buildRows()), overlaid with
     * whichever of them already has a prior SIGNED waiver eligible to be
     * carried into this draw instead of re-requesting a signature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function newDrawRows(Project $project, ?Vendor $contractor): array
    {
        $rows = SwornStatementGenerator::buildRows($project, $contractor);

        return $this->attachPriorSignedWaivers($rows, $project, $contractor, null, true);
    }

    /**
     * Rows (and the draw amount) to reopen an existing draw for editing.
     * Throws when the draw's GCSS already came back signed — its sworn
     * figures are settled.
     *
     * @return array{rows: array<int, array<string, mixed>>, this_payment: string}
     */
    public function editableDraw(SwornStatement $statement, Project $project, ?Vendor $contractor): array
    {
        if ($statement->status === LienWaiverStatus::Signed) {
            throw new DrawAlreadySignedException($statement);
        }

        $tenantId = (int) ($contractor?->id ?? 0);

        $waivers = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('belongs_to_vendor_id', $tenantId)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn ($waiver) => (int) $waiver->vendor_id);

        $rows = SwornStatementGenerator::buildRows($project, $contractor);
        $seen = [];

        foreach ($rows as $i => $row) {
            $vendorId = (int) ($row['vendor_id'] ?? 0);
            $waiver = $waivers->get($vendorId);

            if (! $waiver) {
                // Has money on the job but isn't on this draw — offered
                // unchecked so it can be added.
                $rows[$i]['include'] = false;

                continue;
            }

            $seen[] = $vendorId;
            $rows[$i] = $this->applyWaiverToRow($rows[$i], $waiver);
        }

        // A vendor can be on the draw yet fall out of buildRows() later (its
        // expense was recategorised, its bid deleted). Keep it on the form —
        // dropping it silently would delete its waiver on the next save.
        foreach ($waivers as $vendorId => $waiver) {
            if (in_array((int) $vendorId, $seen, true) || (int) $vendorId === $tenantId) {
                continue;
            }

            $rows[] = $this->applyWaiverToRow([
                'vendor_id' => (int) $vendorId,
                'name' => (string) ($waiver->vendor?->business_name ?? 'Vendor #'.$vendorId),
                'include' => true,
                'kind' => '',
                'is_retail' => false,
                'is_material' => false,
                'contract' => '',
                'paid' => 0.0,
                'this_payment' => '',
            ], $waiver);
        }

        $rows = $this->attachPriorSignedWaivers($rows, $project, $contractor, (int) $statement->id, false);

        return [
            'rows' => $rows,
            'this_payment' => number_format((float) $statement->this_payment, 2, '.', ''),
        ];
    }

    /**
     * Suggestions for the statement's Kind of Work autocomplete: the common
     * trades, plus every kind previously used on this contractor's waivers.
     *
     * @return array<int, string>
     */
    public function kindOfWorkOptions(?Vendor $contractor): array
    {
        $defaults = [
            'Carpentry', 'Plumbing', 'Electrical', 'HVAC', 'Roofing', 'Drywall',
            'Painting', 'Flooring', 'Tile', 'Masonry', 'Concrete', 'Excavation',
            'Demolition', 'Insulation', 'Windows & doors', 'Landscaping',
            'Materials', 'Equipment rental', 'Debris removal',
        ];

        $tenantId = (int) ($contractor?->id ?? 0);

        $saved = WorkType::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->pluck('name');

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

    /**
     * True once the project has a signed contract (the base, type 1, bid
     * created at estimate acceptance) — lien waivers/draws stay hidden until
     * then, same gate the web's project card uses.
     */
    public function hasSignedContract(Project $project, ?Vendor $contractor): bool
    {
        if (! $contractor) {
            return false;
        }

        return Bid::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('vendor_id', $contractor->id)
            ->where('type', 1)
            ->exists();
    }

    /**
     * The project's waivers grouped per draw: each stored statement with the
     * waivers stamped to it (newest draw first), plus any waivers not tied
     * to a draw (manual ones).
     *
     * @return array{draws: Collection, other: Collection}
     */
    public function drawGroups(Project $project, ?Vendor $contractor): array
    {
        $tenantId = (int) ($contractor?->id ?? 0);

        if (! $tenantId) {
            return ['draws' => collect(), 'other' => collect()];
        }

        $statements = SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->get();

        $waivers = LienWaiver::query()
            ->where('project_id', $project->id)
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

    /** Stored GCSS statements for this tenant (optionally scoped to one project). */
    public function swornStatements(?Vendor $contractor, ?Project $project = null): Collection
    {
        $tenantId = (int) ($contractor?->id ?? 0);

        if (! $tenantId) {
            return collect();
        }

        return SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->with('project:id,project_name,address')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * What deleting a draw would remove — shown before the user confirms.
     * Null when the draw doesn't exist or isn't this tenant's.
     *
     * @return array{statement: SwornStatement, number: int, total: int, sent: int, signed: int}|null
     */
    public function deletingDrawSummary(int $statementId, ?Vendor $contractor): ?array
    {
        $tenantId = (int) ($contractor?->id ?? 0);

        $statement = SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->find($statementId);

        if (! $statement) {
            return null;
        }

        $waivers = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('belongs_to_vendor_id', $tenantId)
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

    /**
     * Strip the thousands separators the money inputs format with as you
     * type, before anything validates or saves them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    public function normalize(array $rows, ?string $thisPayment): array
    {
        $thisPayment = $thisPayment !== null ? str_replace(',', '', $thisPayment) : null;

        foreach ($rows as $i => $row) {
            foreach (['contract', 'this_payment'] as $field) {
                if (($row[$field] ?? '') !== '') {
                    $rows[$i][$field] = str_replace(',', '', (string) $row[$field]);
                }
            }
        }

        return [$rows, $thisPayment];
    }

    /**
     * One validation pass covering the draw amount, each row's money fields,
     * and the type of work required for every included vendor.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function buildValidator(array $rows, ?string $thisPayment): ValidatorContract
    {
        $validator = Validator::make(
            ['ssThisPayment' => $thisPayment, 'ssRows' => $rows],
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

        $validator->after(function ($v) use ($rows) {
            foreach ($rows as $i => $row) {
                if (! empty($row['include']) && trim((string) ($row['kind'] ?? '')) === '') {
                    $v->errors()->add("ssRows.{$i}.kind", 'Select the type of work.');
                }
            }
        });

        return $validator;
    }

    /**
     * Create the full draw package: the statement record, the GC's own
     * waiver+affidavit, a waiver for every included sub/supplier, the
     * rendered GCSS PDF (stored), and the producer's copy by email.
     *
     * Caller must have already normalized and validated the payload.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{statement: SwornStatement, gc_waiver_created: bool, sub_sent: int, sub_printed: int, sub_reused: int, saved_bids: int, emailed_producer: bool, send_at: Carbon, download_binary: string, download_filename: string}
     */
    public function createDraw(Project $project, Vendor $contractor, array $rows, float $thisPayment, ?User $actingUser = null): array
    {
        [$rows, $savedBids] = $this->prepareRows($project, $contractor, $rows, null);

        $statement = SwornStatement::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $contractor->id,
            'this_payment' => $thisPayment,
            'created_by_user_id' => $actingUser?->id,
        ]);

        $createdGcWaiver = $this->createGcWaiverForStatement($project, $contractor, $statement->id, $thisPayment, $actingUser?->id);
        $subWaivers = $this->createSubWaiversForStatement($project, $contractor, $statement->id, $rows, $actingUser?->id);

        $doc = SwornStatementGenerator::generate(
            $project,
            $contractor,
            $rows,
            $thisPayment,
            $statement->drawNumber(),
            $statement->id,
        );

        $relativePath = sprintf('sworn-statements/%d/%d/%s', $project->id, $statement->id, $doc['filename']);
        Storage::disk('files')->put($relativePath, $doc['binary']);
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
                $gcDoc = LienWaiverDocumentGenerator::generate($gcWaiver);
                $merged = PdfMerger::merge([$doc['binary'], $gcDoc['binary']]);

                if ($merged !== null && $merged !== $doc['binary']) {
                    $downloadBinary = $merged;
                    $downloadName = DrawPackageMail::packageFilenameFor($statement);
                }
            } catch (\Throwable) {
                // GC waiver render failed — the GCSS alone still downloads.
            }
        }

        // The producer also gets the package by email for their records; the
        // job flips the statement + GC waiver to Sent after the actual send.
        $emailedProducer = false;
        if ($actingUser && ! empty($actingUser->email)) {
            SendDrawPackageJob::dispatch($statement->id, $actingUser->id);
            $emailedProducer = true;
        }

        return [
            'statement' => $statement->fresh(),
            'gc_waiver_created' => $createdGcWaiver,
            'sub_sent' => $subWaivers['sent'],
            'sub_printed' => $subWaivers['printed'],
            'sub_reused' => $subWaivers['reused'] ?? 0,
            'saved_bids' => $savedBids,
            'emailed_producer' => $emailedProducer,
            'send_at' => $this->waiverSendAt($contractor),
            'download_binary' => $downloadBinary,
            'download_filename' => $downloadName,
        ];
    }

    /**
     * Apply edits to an existing draw. Locking is per waiver, not per draw: a
     * signed waiver is frozen, while every unsigned one on the same draw
     * stays fully adjustable. Throws when the draw's GCSS was signed while
     * it was being edited.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{statement: SwornStatement, revised: int, added_sent: int, added_printed: int, added_reused: int, removed: int, frozen: int, saved_bids: int}
     */
    public function updateDraw(SwornStatement $statement, Project $project, Vendor $contractor, array $rows, float $thisPayment, ?User $actingUser = null): array
    {
        if ($statement->status === LienWaiverStatus::Signed) {
            throw new DrawAlreadySignedException($statement);
        }

        $tenantId = (int) $contractor->id;

        $existing = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('belongs_to_vendor_id', $tenantId)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn ($waiver) => (int) $waiver->vendor_id);

        [$rows, $savedBids] = $this->prepareRows($project, $contractor, $rows, (int) $statement->id);

        $revised = 0;
        $removed = 0;
        $frozen = 0;
        $addedRows = [];

        foreach ($rows as $row) {
            $vendorId = (int) ($row['vendor_id'] ?? 0);

            // The GC's own waiver tracks the draw amount, handled below.
            if ($vendorId === 0 || $vendorId === $tenantId) {
                continue;
            }

            $waiver = $existing->get($vendorId);
            $included = ! empty($row['include']);

            if ($waiver && $waiver->isSigned()) {
                $frozen++;

                continue;
            }

            if (! $waiver) {
                if ($included) {
                    $addedRows[] = $row;
                }

                continue;
            }

            if (! $included) {
                $waiver->delete();
                $removed++;

                continue;
            }

            if (! empty($row['reuse_signed']) && ! empty($row['prior_signed_id'])) {
                $waiver->delete();
                $addedRows[] = $row;

                continue;
            }

            if ($this->reviseWaiverFromRow($waiver, $row, $contractor)) {
                $revised++;
            }
        }

        $added = $addedRows !== []
            ? $this->createSubWaiversForStatement($project, $contractor, $statement->id, $addedRows, $actingUser?->id)
            : ['sent' => 0, 'printed' => 0, 'reused' => 0];

        // The GC's own waiver swears to the draw amount, so it moves with it.
        $gcWaiver = $existing->get($tenantId);

        if ($gcWaiver && ! $gcWaiver->isSigned() && abs((float) $gcWaiver->amount - $thisPayment) >= 0.01) {
            $this->reviseWaiver($gcWaiver, ['amount' => $thisPayment], $contractor);
            $revised++;
        }

        // The GCSS lists every vendor and amount, so it re-renders whenever
        // anything on the draw moves.
        $doc = SwornStatementGenerator::generate(
            $project,
            $contractor,
            $rows,
            $thisPayment,
            $statement->drawNumber(),
            $statement->id,
        );

        // The renderer falls back to raw HTML when Chrome is unavailable, and
        // the GCSS filename carries today's date — so a failed re-render
        // would write to a NEW path. The amount still has to be saved; only
        // the document is held back.
        if (str_starts_with((string) $doc['binary'], '%PDF')) {
            $relativePath = sprintf('sworn-statements/%d/%d/%s', $project->id, $statement->id, $doc['filename']);
            Storage::disk('files')->put($relativePath, $doc['binary']);
            $statement->forceFill([
                'filename' => $doc['filename'],
                'path' => $relativePath,
                'this_payment' => $thisPayment,
            ])->save();
        } else {
            Log::warning('Sworn statement re-render produced HTML, not a PDF — keeping the existing document.', [
                'sworn_statement_id' => $statement->id,
                'kept_path' => $statement->path,
            ]);

            $statement->forceFill(['this_payment' => $thisPayment])->save();
        }

        return [
            'statement' => $statement->fresh(),
            'revised' => $revised,
            'added_sent' => $added['sent'] ?? 0,
            'added_printed' => $added['printed'] ?? 0,
            'added_reused' => $added['reused'] ?? 0,
            'removed' => $removed,
            'frozen' => $frozen,
            'saved_bids' => $savedBids,
        ];
    }

    /**
     * Delete a whole draw: the GCSS and every waiver stamped with it, all
     * soft-deleted (recoverable, stops blocking dedup on the next draw) —
     * the stored PDFs stay on disk.
     */
    public function deleteDraw(SwornStatement $statement, ?Vendor $contractor): void
    {
        $tenantId = (int) ($contractor?->id ?? 0);

        LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('belongs_to_vendor_id', $tenantId)
            ->whereNull('deleted_at')
            ->get()
            ->each
            ->delete();

        $statement->delete();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Shared pre-processing for both a new draw and an edited one: drop any
     * row whose vendor wasn't actually offered by buildRows() (or already on
     * this draw) — ssRows round-trips through the browser/app on every
     * request and isn't otherwise checksummed, so a tampered vendor_id must
     * never be able to create a Bid, rewrite a Vendor, or mail a lien waiver
     * to an unrelated company — then save typed contract amounts as bids and
     * selected kinds of work as WorkTypes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected function prepareRows(Project $project, Vendor $contractor, array $rows, ?int $editingStatementId): array
    {
        $allowedVendorIds = $this->allowedVendorIds($project, $contractor, $editingStatementId);
        $rows = collect($rows)
            ->filter(fn ($row) => $allowedVendorIds->contains((int) ($row['vendor_id'] ?? 0)))
            ->values()
            ->all();

        // Contract amounts typed here for vendors with no bid on the project
        // are saved as their bid, so the next statement prefills itself.
        // Existing bids are never overwritten (they may be structured base +
        // extras).
        $savedBids = 0;

        foreach ($rows as $row) {
            $contract = (string) ($row['contract'] ?? '');

            if (empty($row['include']) || $contract === '' || (float) $contract <= 0) {
                continue;
            }

            $hasBid = Bid::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('vendor_id', (int) $row['vendor_id'])
                ->exists();

            if (! $hasBid) {
                Bid::withoutGlobalScopes()->create([
                    'project_id' => $project->id,
                    'vendor_id' => (int) $row['vendor_id'],
                    'amount' => (float) $contract,
                    'type' => 1,
                ]);
                $savedBids++;
            }
        }

        $this->saveWorkTypesFromStatement($contractor, $rows);

        return [$rows, $savedBids];
    }

    /**
     * The vendor ids a draw's rows may legitimately carry: whoever
     * buildRows() would offer today plus, when editing, whoever is already
     * on that draw's existing waivers. The contractor's own id is always
     * allowed (the GC's own row).
     */
    protected function allowedVendorIds(Project $project, Vendor $contractor, ?int $editingStatementId): Collection
    {
        $allowed = collect(SwornStatementGenerator::buildRows($project, $contractor))
            ->pluck('vendor_id')
            ->map(fn ($id) => (int) $id);

        if ($editingStatementId) {
            $existing = LienWaiver::withoutGlobalScopes()
                ->where('sworn_statement_id', $editingStatementId)
                ->pluck('vendor_id')
                ->map(fn ($id) => (int) $id);

            $allowed = $allowed->merge($existing);
        }

        return $allowed->merge([(int) $contractor->id])->unique();
    }

    /**
     * Persist each included row's Kind of Work: find-or-create the WorkType
     * (per tenant, case-insensitive) and set it as the vendor's default so
     * future statements, planner entries and tasks prefill it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function saveWorkTypesFromStatement(Vendor $contractor, array $rows): void
    {
        foreach ($rows as $row) {
            $kind = trim((string) ($row['kind'] ?? ''));
            $vendorId = (int) ($row['vendor_id'] ?? 0);

            if (empty($row['include']) || $kind === '' || $vendorId === 0) {
                continue;
            }

            $workType = WorkType::resolve((int) $contractor->id, $kind);

            if (! $workType) {
                continue;
            }

            Vendor::withoutGlobalScopes()
                ->whereKey($vendorId)
                ->where(fn ($q) => $q->whereNull('work_type_id')->orWhere('work_type_id', '!=', $workType->id))
                ->update(['work_type_id' => $workType->id]);
        }
    }

    /**
     * Create the GC's own waiver+affidavit for the draw: claimant and
     * payer-of-record are both the GC, the payer on the document is the
     * project client, amount is "Net amount of this payment". Skipped when
     * the draw is zero or the GC already has an open waiver (re-stamped onto
     * this draw instead).
     */
    protected function createGcWaiverForStatement(Project $project, Vendor $contractor, int $statementId, float $amount, ?int $actingUserId): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $hasOpen = LienWaiver::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('vendor_id', $contractor->id)
            ->where('belongs_to_vendor_id', $contractor->id)
            ->whereNull('deleted_at')
            ->whereIn('status', [LienWaiverStatus::Draft, LienWaiverStatus::Sent])
            ->exists();

        if ($hasOpen) {
            // Re-stamp the lingering GC draft onto this draw so grouping and
            // the per-draw download include it.
            LienWaiver::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('vendor_id', $contractor->id)
                ->where('belongs_to_vendor_id', $contractor->id)
                ->whereNull('deleted_at')
                ->whereIn('status', [LienWaiverStatus::Draft, LienWaiverStatus::Sent])
                ->update(['sworn_statement_id' => $statementId]);

            return false;
        }

        // ClientScope limits clients to ones linked to the auth vendor — load
        // directly so the payer block always fills in.
        $client = $project->client()->withoutGlobalScopes()->first();
        $payerCityStateZip = trim(sprintf(
            '%s%s %s',
            $client?->city ?? $project->city ?? '',
            ($client?->state ?? $project->state)
                ? ', '.($client?->state ?? $project->state)
                : '',
            $client?->zip_code ?? $project->zip_code ?? '',
        ));

        LienWaiver::create([
            'belongs_to_vendor_id' => $contractor->id,
            'vendor_id' => $contractor->id,
            'project_id' => $project->id,
            'sworn_statement_id' => $statementId,
            'check_id' => null,
            'payment_id' => null,
            'type' => LienWaiverType::ConditionalProgress,
            'status' => LienWaiverStatus::Draft,
            'amount' => $amount,
            'exceptions_amount' => 0,
            'through_date' => now()->toDateString(),
            'jurisdiction' => $this->projectJurisdiction($project),
            'notes' => json_encode([
                'manual' => true,
                'source' => 'sworn_statement',
                'payer' => [
                    'name' => (string) ($client?->legal_name ?? ''),
                    'address' => trim((string) ($client?->address ?? $project->address ?? '')),
                    'city_state_zip' => $payerCityStateZip,
                ],
            ]),
            'created_by_user_id' => $actingUserId,
        ]);

        return true;
    }

    /**
     * Create a sub Waiver of Lien (to date) for each included row, unless
     * that sub already has an open (draft/sent) waiver on the project.
     * Waivers are emailed only when the vendor has an email on file
     * (retail/material suppliers usually don't) — the rest stay as drafts to
     * print and hand over.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{sent: int, printed: int, reused: int}
     */
    protected function createSubWaiversForStatement(Project $project, Vendor $contractor, int $statementId, array $rows, ?int $actingUserId): array
    {
        $jurisdiction = $this->projectJurisdiction($project);
        $throughDate = now()->toDateString();
        $sendAt = $this->waiverSendAt($contractor);
        $sent = 0;
        $printed = 0;
        $reused = 0;

        $rowVendorIds = collect($rows)->pluck('vendor_id')->filter()->map(fn ($id) => (int) $id);
        $withBusinessEmail = Vendor::withoutGlobalScopes()
            ->whereIn('id', $rowVendorIds)
            ->whereNotNull('business_email')
            ->where('business_email', '!=', '')
            ->pluck('id');
        $withUserEmail = UserVendor::query()
            ->whereIn('vendor_id', $rowVendorIds)
            ->where('is_employed', true)
            ->whereIn('user_id', fn ($q) => $q->select('id')->from('users')->whereNotNull('email'))
            ->pluck('vendor_id');
        $emailableVendorIds = $withBusinessEmail->merge($withUserEmail)->map(fn ($id) => (int) $id)->unique();

        foreach ($rows as $row) {
            if (empty($row['include'])) {
                continue;
            }

            $vendorId = (int) ($row['vendor_id'] ?? 0);

            if ($vendorId === 0 || $vendorId === (int) $contractor->id) {
                continue;
            }

            $paid = (float) ($row['paid'] ?? 0);
            $thisPayment = ($row['this_payment'] ?? '') !== ''
                ? (float) str_replace(',', '', (string) $row['this_payment'])
                : 0.0;
            $amount = $paid + $thisPayment;

            if ($amount <= 0) {
                continue;
            }

            $openWaiver = LienWaiver::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('vendor_id', $vendorId)
                ->where('belongs_to_vendor_id', $contractor->id)
                ->whereNull('deleted_at')
                ->whereIn('status', [LienWaiverStatus::Draft, LienWaiverStatus::Sent])
                ->orderByDesc('id')
                ->first();

            $notes = ['manual' => true, 'source' => 'sworn_statement'];
            $kind = trim((string) ($row['kind'] ?? ''));
            if ($kind !== '') {
                $notes['kind_of_work'] = $kind;
            }
            if (! empty($row['is_retail'])) {
                $notes['retail'] = true;
            }
            if (! empty($row['is_material'])) {
                $notes['material'] = true;
            }

            $canEmail = $emailableVendorIds->contains($vendorId);

            $typedContract = ($row['contract'] ?? '') !== '' ? (float) $row['contract'] : null;
            $isFinal = $typedContract !== null && $amount + 0.009 >= $typedContract;

            // Reuse a previously SIGNED waiver instead of chasing a fresh
            // signature, when the row opted in.
            if (! empty($row['reuse_signed']) && ! empty($row['prior_signed_id'])) {
                $prior = LienWaiver::withoutGlobalScopes()
                    ->whereKey((int) $row['prior_signed_id'])
                    ->where('project_id', $project->id)
                    ->where('belongs_to_vendor_id', $contractor->id)
                    ->where('vendor_id', $vendorId)
                    ->whereNull('deleted_at')
                    ->where('status', LienWaiverStatus::Signed)
                    ->whereNotNull('signed_path')
                    ->first();

                if ($prior) {
                    LienWaiver::create([
                        'belongs_to_vendor_id' => $contractor->id,
                        'vendor_id' => $vendorId,
                        'project_id' => $project->id,
                        'sworn_statement_id' => $statementId,
                        'check_id' => null,
                        'payment_id' => null,
                        'type' => $prior->type,
                        'status' => LienWaiverStatus::Signed,
                        'amount' => $prior->amount,
                        'exceptions_amount' => $prior->exceptions_amount,
                        'through_date' => $prior->through_date,
                        'jurisdiction' => $prior->jurisdiction,
                        'document_hash' => $prior->document_hash,
                        'draft_path' => $prior->draft_path,
                        'signed_path' => $prior->signed_path,
                        'sent_at' => $prior->sent_at,
                        'signed_at' => $prior->signed_at,
                        'notes' => json_encode(array_merge($notes, ['reused_from' => $prior->id])),
                        'created_by_user_id' => $actingUserId,
                    ]);

                    $reused++;

                    continue;
                }
            }

            if ($openWaiver) {
                if ($openWaiver->status === LienWaiverStatus::Draft) {
                    $existingNotes = json_decode((string) $openWaiver->notes, true) ?: [];
                    unset($existingNotes['kind_of_work'], $existingNotes['open'], $existingNotes['retail']);

                    $openWaiver->forceFill([
                        'type' => $isFinal ? LienWaiverType::UnconditionalFinal : LienWaiverType::ConditionalProgress,
                        'sworn_statement_id' => $statementId,
                        'amount' => $amount,
                        'through_date' => $throughDate,
                        'notes' => json_encode(array_merge($existingNotes, $notes)),
                    ])->save();

                    SendLienWaiverSigningRequestJob::dispatch($openWaiver->id)->delay($sendAt);
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
                'project_id' => $project->id,
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
                'created_by_user_id' => $actingUserId,
            ]);

            // Every waiver goes out by email: straight to the vendor when
            // they have an address on file, otherwise to the GC user with a
            // "please forward to {vendor}" banner.
            SendLienWaiverSigningRequestJob::dispatch($waiver->id)->delay($sendAt);
            if ($canEmail) {
                $sent++;
            } else {
                $printed++;
            }
        }

        return ['sent' => $sent, 'printed' => $printed, 'reused' => $reused];
    }

    /**
     * Re-price one waiver from its statement row. Returns whether anything
     * actually changed — an untouched row must not bump the revision, or
     * every save would void documents nobody edited.
     */
    protected function reviseWaiverFromRow(LienWaiver $waiver, array $row, Vendor $contractor): bool
    {
        $paid = (float) ($row['paid'] ?? 0);
        $entry = ($row['this_payment'] ?? '') !== ''
            ? (float) str_replace(',', '', (string) $row['this_payment'])
            : 0.0;

        $notes = json_decode((string) $waiver->notes, true) ?: [];
        $kind = trim((string) ($row['kind'] ?? ''));

        $changes = [];

        $amount = round($paid + $entry, 2);
        if (abs((float) $waiver->amount - $amount) >= 0.01) {
            $changes['amount'] = $amount;
        }

        if ($kind !== '' && $kind !== trim((string) ($notes['kind_of_work'] ?? ''))) {
            $notes['kind_of_work'] = $kind;
            $changes['notes'] = json_encode($notes);
        }

        if ($changes === []) {
            return false;
        }

        $this->reviseWaiver($waiver, $changes, $contractor);

        return true;
    }

    /**
     * Persist a revision: apply the changes, bump the revision counter,
     * refresh the tamper hash, re-render the stored PDF, and re-notify the
     * vendor. Status and access_token are deliberately preserved — the
     * vendor's link keeps working and now returns the corrected document.
     */
    protected function reviseWaiver(LienWaiver $waiver, array $changes, Vendor $contractor): void
    {
        $amountChanged = array_key_exists('amount', $changes);

        $waiver->forceFill($changes);
        $waiver->document_revision = (int) ($waiver->document_revision ?: 1) + 1;
        $waiver->document_hash = $waiver->computeDocumentHash();
        $waiver->save();

        try {
            LienWaiverDocumentGenerator::generate($waiver->refresh(), store: true);
        } catch (\Throwable) {
            // The record is already correct; the document rebuilds on download.
        }

        // Only an amount change is worth re-emailing over — a corrected kind
        // of work doesn't invalidate a signature.
        if ($amountChanged && $waiver->status === LienWaiverStatus::Sent) {
            SendLienWaiverSigningRequestJob::dispatch($waiver->id, superseded: true)
                ->delay($this->waiverSendAt($contractor));
        }
    }

    /**
     * Overlay an existing waiver onto a statement row. The stored amount is
     * paid-to-date PLUS what was entered for the draw, so the entry is
     * re-derived against today's paid figure.
     */
    protected function applyWaiverToRow(array $row, LienWaiver $waiver): array
    {
        $entry = round((float) $waiver->amount - (float) ($row['paid'] ?? 0), 2);
        $notes = json_decode((string) $waiver->notes, true) ?: [];
        $kind = trim((string) ($notes['kind_of_work'] ?? ''));

        $row['include'] = true;
        $row['waiver_id'] = (int) $waiver->id;
        $row['waiver_signed'] = $waiver->isSigned();
        $row['waiver_status'] = $waiver->statusLabel();
        $row['this_payment'] = $entry > 0 ? number_format($entry, 2, '.', '') : '';

        if ($kind !== '') {
            $row['kind'] = $kind;
        }

        return $row;
    }

    /**
     * Stamp each statement row with the sub's latest SIGNED waiver on this
     * project (id + amount) so the caller can offer reusing it instead of
     * chasing a fresh signature.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function attachPriorSignedWaivers(array $rows, Project $project, ?Vendor $contractor, ?int $excludeStatementId, bool $defaultOn): array
    {
        $contractorId = (int) ($contractor?->id ?? 0);
        $vendorIds = collect($rows)
            ->pluck('vendor_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $contractorId);

        $signedByVendor = $vendorIds->isEmpty() ? collect() : LienWaiver::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('belongs_to_vendor_id', $contractorId)
            ->whereIn('vendor_id', $vendorIds)
            ->whereNull('deleted_at')
            ->where('status', LienWaiverStatus::Signed)
            ->whereNotNull('signed_path')
            ->when($excludeStatementId, fn ($q) => $q->where(function ($q) use ($excludeStatementId) {
                $q->whereNull('sworn_statement_id')
                    ->orWhere('sworn_statement_id', '!=', $excludeStatementId);
            }))
            ->orderBy('id')
            ->get()
            ->keyBy('vendor_id');

        foreach ($rows as $i => $row) {
            if (! empty($row['waiver_signed'])) {
                $rows[$i]['prior_signed_id'] = null;
                $rows[$i]['prior_signed_amount'] = null;
                $rows[$i]['reuse_signed'] = false;

                continue;
            }

            $prior = $signedByVendor->get((int) ($row['vendor_id'] ?? 0));
            $paid = (float) ($row['paid'] ?? 0);

            $rows[$i]['prior_signed_id'] = $prior?->id;
            $rows[$i]['prior_signed_amount'] = $prior ? (string) $prior->amount : null;
            $rows[$i]['reuse_signed'] = $defaultOn
                && $prior !== null
                && abs((float) $prior->amount - $paid) < 0.01;
        }

        return $rows;
    }

    /**
     * When a waiver email should actually leave — subs get lien waiver
     * requests during the GC's working day, never overnight.
     */
    protected function waiverSendAt(?Vendor $contractor): Carbon
    {
        return $contractor
            ? $contractor->nextBusinessHoursOpening()
            : now();
    }

    /** Map the project's state to a lien-waiver jurisdiction code. */
    protected function projectJurisdiction(Project $project): string
    {
        return app(LienWaiverCreator::class)->jurisdictionFor($project);
    }
}
