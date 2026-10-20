<?php

namespace App\Livewire\Estimates;

use App\Livewire\Projects\ProjectFinances;
use App\Mail\WelcomeClient;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\Bid;
use App\Livewire\Estimates\EstimatesIndex;
use App\Support\ProjectDocumentGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Mail\EstimateSigningInvite;
use App\Services\Estimates\EstimateLineItemWriter;
use App\Services\Estimates\EstimateSectionWriter;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;

use App\Support\EstimateDocumentGenerator;

class EstimateShow extends Component
{
    use AuthorizesRequests;

    /**
     * Column defs for the Payment Schedule card — the header row and the cells
     * render from this one array, same contract as every other card table.
     * Widths must sum to 100.
     *
     * @return array<int, array{label: string, width: string, skeletonWidth?: string}>
     */
    public static function paymentColumnDefs(): array
    {
        return [
            ['label' => 'Payment', 'width' => 'w-[70%] min-w-0', 'skeletonWidth' => 'w-40'],
            ['label' => 'Amount', 'width' => 'w-[30%]', 'skeletonWidth' => 'w-16'],
        ];
    }

    public Estimate $estimate;

    public $sections = [];

    public $trashedSections = [];

    public $trashedLineItems = [];

    public string $sortBy = 'order';

    public string $sortDirection = 'asc';

    public bool $showChanges = false;

    public bool $showAllowances = true;

    public array $recentChanges = ['line_items' => [], 'sections' => [], 'since' => null];

    protected $listeners = ['refreshComponent' => 'handleExternalRefresh'];

    protected function rules()
    {
        return [
            'sections.*.name' => 'required',
        ];
    }

    public function mount()
    {
        $this->authorize('view', $this->estimate);

        $this->sections = $this->estimate->estimate_sections->toArray();
        $this->trashedSections = $this->estimate->estimate_sections()->onlyTrashed()->get()->toArray();
        $this->loadTrashedLineItems();

        //11-1-2023 MOVE to EstiamteCreate
        //start with one section and an ADD card/button for line items
        // Auto-scaffolding a first section is a write: only someone who could
        // otherwise edit the estimate (never a client-browsing viewer) gets one.
        if (empty($this->sections) && auth()->user()?->can('update', $this->estimate)) {
            $this->create_new_section();
        }

        $this->estimate_refresh();
    }

    public function print_reimbursements(): StreamedResponse
    {
        $project = $this->estimate->project;

        if ($project === null) {
            abort(404);
        }

        $this->authorize('view', $project);

        $document = ProjectDocumentGenerator::generateReimbursements($project);

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    protected function loadTrashedLineItems(): void
    {
        $this->trashedLineItems = EstimateLineItem::onlyTrashed()
            ->where('estimate_id', $this->estimate->id)
            ->whereHas('section', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->get()
            ->groupBy('section_id')
            ->map(fn ($items) => $items->toArray())
            ->toArray();
    }

    /**
     * Handle refresh dispatched from child components (e.g. line-item-create).
     * These always involve data changes, so refresh financials too.
     */
    public function handleExternalRefresh(): void
    {
        $this->estimate_refresh();
        $this->refreshFinancialIslands();

        if ($this->showChanges) {
            $this->updatedShowChanges();
        }
    }

    public function toggleChanges(): void
    {
        $this->showChanges = ! $this->showChanges;
        $this->updatedShowChanges();
        $this->forceRender();
    }

    public function toggleAllowances(): void
    {
        $this->showAllowances = ! $this->showAllowances;
        $this->forceRender();
    }

    public function updatedShowChanges(): void
    {
        if ($this->showChanges) {
            $this->recentChanges = EstimateDocumentGenerator::collectRecentChanges($this->estimate);
        } else {
            $this->recentChanges = ['line_items' => [], 'sections' => [], 'since' => null];
        }
    }

    public function estimate_refresh()
    {
        // Refresh the estimate model and eager load relationships
        $this->estimate = $this->estimate->fresh(['estimate_sections.estimate_line_items.allowances']);
        
        // Get fresh section data with updated totals from database
        $sections = $this->estimate->estimate_sections()
            ->with(['estimate_line_items.allowances', 'bid'])
            ->get();

        // If totals got out of sync (e.g. section was restored but total became 0), fix it.
        // Only repair obviously-broken totals to avoid unintended changes.
        $bidIdsToRecalculate = [];
        foreach ($sections as $section) {
            $computedTotal = (float) $section->estimate_line_items->sum('total');
            $storedTotal = (float) $section->total;

            if ($storedTotal === 0.0 && $computedTotal > 0.0) {
                $section->total = $computedTotal;
                $section->save();

                if (! empty($section->bid_id)) {
                    $bidIdsToRecalculate[] = $section->bid_id;
                }
            }
        }

        foreach (array_unique($bidIdsToRecalculate) as $bidId) {
            $bid = Bid::find($bidId);
            if (! $bid) {
                continue;
            }

            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

        $this->sections = $sections->toArray();

        // Get trashed sections for restore functionality
        $this->trashedSections = $this->estimate->estimate_sections()
            ->onlyTrashed()
            ->get()
            ->toArray();

        // Get trashed line items for restore functionality
        $this->loadTrashedLineItems();
    }

    public function lineItemRestore(int $lineItemId): void
    {
        $this->authorize('update', $this->estimate);

        $lineItem = $this->estimate->estimate_line_items()->onlyTrashed()->findOrFail($lineItemId);
        $section = app(EstimateSectionWriter::class)->restoreLineItem($lineItem);

        $this->estimate_refresh();
        $this->refreshFinancialIslands();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Line Item Restored',
            text: $lineItem->name . ' has been restored to ' . ($section->name ?? 'Unnamed Section') . '.',
        );
    }

    public function create_new_section($name = null, $estimate_id = null)
    {
        $estimate = $this->estimate ?? Estimate::findOrFail($estimate_id);

        return app(EstimateSectionWriter::class)->addSection($estimate, $name);
    }

    public function sectionAdd()
    {
        $this->authorize('update', $this->estimate);

        $this->create_new_section();
        $this->estimate_refresh();
        $this->refreshFinancialIslands();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Added',
            // route / href / wire:click
            text: 'Section Added',
        );
    }

    public function sectionRestore(int $sectionId)
    {
        $this->authorize('update', $this->estimate);

        $section = $this->estimate->estimate_sections()->withTrashed()->findOrFail($sectionId);
        app(EstimateSectionWriter::class)->restoreSection($section);

        $this->estimate_refresh();
        $this->refreshFinancialIslands();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Restored',
            text: 'Section ' . ($section->name ?? 'Unnamed') . ' has been restored.',
        );
    }

    public function sectionDelete($section_index)
    {
        $this->authorize('update', $this->estimate);

        $section_data = $this->sections[$section_index];
        $section = $this->estimate->estimate_sections()->findOrFail($section_data['id']);

        app(EstimateSectionWriter::class)->deleteSection($section);

        $this->estimate_refresh();
        $this->refreshFinancialIslands();

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Disabled',
            text: 'Section and all its line items have been disabled.',
        );
    }

    public function sectionUpdate($section_index)
    {
        $this->authorize('update', $this->estimate);

        $section = $this->estimate->estimate_sections()->findOrFail($this->sections[$section_index]['id']);
        $section = app(EstimateSectionWriter::class)->renameSection($section, $this->sections[$section_index]['name']);
        $this->estimate_refresh();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Name Updated',
            // route / href / wire:click
            text: 'Section '.$section->name,
        );
    }

    /**
     * A section is only a change order once the contract exists. Delegates to
     * EstimateSectionWriter, the single source of this logic shared with the
     * Hive Contractors app's API.
     */
    protected function maybeCreateChangeOrderBid(EstimateSection $section): void
    {
        app(EstimateSectionWriter::class)->maybeCreateChangeOrderBid($section);
    }

    public function sendInvite(): void
    {
        $this->authorize('update', $this->estimate);

        $result = app(\App\Services\Estimates\EstimateInviteSender::class)->send($this->estimate);

        if ($result['reason'] !== null) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'warning',
                heading: $result['kind'] === \App\Services\Estimates\EstimateInviteSender::KIND_SIGNING ? 'No Pending Signers' : ($result['kind'] === '' ? 'Estimate Not Found' : 'No Recipients'),
                text: $result['reason'],
            );

            return;
        }

        $isSigning = $result['kind'] === \App\Services\Estimates\EstimateInviteSender::KIND_SIGNING;

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $isSigning ? 'Signing Invites Sent' : 'Invites Sent',
            text: 'Sent '.$result['sent'].' '.($isSigning ? 'contract signing invite' : 'invite').($result['sent'] !== 1 ? 's' : '').'.',
        );
    }

    public function disableEstimate()
    {
        $this->authorize('delete', $this->estimate);

        $projectId = $this->estimate->project->id;
        $this->estimate->delete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Disabled',
            text: '',
        );

        return $this->redirect(route('projects.show', ['project' => $projectId]), navigate: true);
    }

    public function removeEstimate()
    {
        $this->authorize('delete', $this->estimate);

        $projectId = $this->estimate->project->id;
        $this->estimate->delete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Deleted',
            text: '',
        );

        return $this->redirect(route('projects.show', ['project' => $projectId]), navigate: true);
    }

    public function activateEstimate()
    {
        $this->authorize('restore', $this->estimate);

        $this->estimate->restore();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Restored',
            text: '',
        );
    }

    public function sectionDuplicate($section_index)
    {
        $this->authorize('update', $this->estimate);

        $section_data = $this->sections[$section_index];
        $section = $this->estimate->estimate_sections()->findOrFail($section_data['id']);

        $new_section = app(EstimateSectionWriter::class)->duplicateSection($section);

        $this->estimate_refresh();
        $this->refreshFinancialIslands();

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Duplicated',
            // route / href / wire:click
            text: 'Section '.$new_section->name,
        );
    }

    public function getEstimateTotalProperty()
    {
        return collect($this->sections)->sum('total');
    }

    //$type = [estimate, invoice, work order]
    public function create_pdf($type)
    {
        $document = EstimateDocumentGenerator::generate($this->estimate, $type, showChanges: $this->showChanges, showAllowances: $this->showAllowances);

        // Force immediate component state preservation before download
        $this->skipRender();

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

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
     * Reorders a section — delegates to App\Services\Estimates\
     * EstimateSectionWriter::moveSection(), shared with the Hive
     * Contractors app's EstimateSectionController::move().
     */
    public function sort_sections($item, $position): void
    {
        $this->authorize('update', $this->estimate);

        $section = $this->estimate->estimate_sections()->findOrFail($item);
        app(EstimateSectionWriter::class)->moveSection($section, $position);
        $this->estimate_refresh();
    }

    /**
     * Reorders a line item, optionally into a different section — delegates
     * to App\Services\Estimates\EstimateLineItemWriter::moveLineItem(),
     * shared with the Hive Contractors app's EstimateLineItemController::move().
     */
    public function sort_line_item($item, $position, $sectionId = null): void
    {
        $this->authorize('update', $this->estimate);

        $line_item = $this->estimate->estimate_line_items()->findOrFail($item);

        $sectionId = $sectionId ? (int) $sectionId : null;
        $isCrossSection = $sectionId && $sectionId !== $line_item->section_id;

        // The destination must be one of this estimate's own sections too.
        $section = $isCrossSection ? $this->estimate->estimate_sections()->findOrFail($sectionId) : null;

        app(EstimateLineItemWriter::class)->moveLineItem($line_item, $position, $section);

        if ($isCrossSection) {
            $this->estimate_refresh();
            $this->refreshFinancialIslands();
        } else {
            $this->estimate_refresh();
        }
    }

    /**
     * Refresh financial islands and related components after data changes.
     * Only call this when financial data actually changes (add/remove/restore),
     * NOT on sort operations which only change order.
     */
    protected function refreshFinancialIslands(): void
    {
        $this->dispatch('refreshComponent')->to('estimates.estimate-accept');
        $this->dispatch('refresh')->to('projects.project-finances');
    }

    public function export_csv()
    {
        $document = EstimateDocumentGenerator::generateXlsx($this->estimate, $this->showAllowances);

        $this->skipRender();

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }



    #[Title('Estimate')]
    /**
     * "{section}.{item}" display numbers keyed by line item id, mirroring the
     * page's numbering (sections in $sections order, items by current sort).
     */
    public function getLineItemNumbersProperty(): array
    {
        $numbers = [];

        foreach (array_values($this->sections ?? []) as $sectionIndex => $sectionData) {
            $section = $this->estimate->estimate_sections->find($sectionData['id'] ?? null);

            if (! $section) {
                continue;
            }

            $items = $section->estimate_line_items
                ->sortBy($this->sortBy, SORT_REGULAR, $this->sortDirection === 'desc')
                ->values();

            foreach ($items as $itemIndex => $item) {
                $numbers[$item->id] = ($sectionIndex + 1).'.'.($itemIndex + 1);
            }
        }

        return $numbers;
    }

    /**
     * Credit pointers for badges: original line item id => list of the
     * display numbers of its credit line items ("3.1").
     */
    public function getCreditBadgesProperty(): array
    {
        $numbers = $this->lineItemNumbers;
        $badges = [];

        foreach ($this->estimate->estimate_sections as $section) {
            foreach ($section->estimate_line_items as $item) {
                if ($item->credit_for_id && isset($numbers[$item->id])) {
                    $badges[$item->credit_for_id][] = $numbers[$item->id];
                }
            }
        }

        return $badges;
    }

    public function render()
    {
        $this->authorize('view', $this->estimate);

        return view('livewire.estimates.show');
    }

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('livewire.estimates.show-placeholder');
    }
}
