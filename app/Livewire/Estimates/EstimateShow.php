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
        $this->sections = $this->estimate->estimate_sections->toArray();
        $this->trashedSections = $this->estimate->estimate_sections()->onlyTrashed()->get()->toArray();
        $this->loadTrashedLineItems();

        //11-1-2023 MOVE to EstiamteCreate
        //start with one section and an ADD card/button for line items
        if (empty($this->sections)) {
            $this->create_new_section();
            $this->estimate_refresh();
        } else {
            $this->estimate_refresh();
        }
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
        $lineItem = EstimateLineItem::onlyTrashed()->findOrFail($lineItemId);
        $section = EstimateSection::findOrFail($lineItem->section_id);

        // Look up the original order from the activity log recorded on deletion.
        // displace() logs this FIRST (before LogsActivity fires its own 'deleted' entry),
        // so we use orderBy('id') ASC to get the displace log which carries the order.
        $deletedActivity = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', EstimateLineItem::class)
            ->where('subject_id', $lineItemId)
            ->where('event', 'deleted')
            ->orderBy('id')
            ->first();

        $originalOrder = isset($deletedActivity->properties['old']['order'])
            ? (int) $deletedActivity->properties['old']['order']
            : null;

        // Restore the line item (order remains 999999 until moved below)
        $lineItem->restore();

        // Re-insert at the original position so numbering is preserved.
        // Falls back to end-of-list when no activity record exists.
        if ($originalOrder !== null) {
            $lineItem->move($originalOrder);
        } else {
            $currentMaxOrder = EstimateLineItem::where('section_id', $section->id)
                ->where('order', '<', 999999)
                ->max('order');
            $lineItem->order = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;
            $lineItem->save();
        }

        // Update section total
        $section->total = $section->estimate_line_items()->sum('total');
        $section->save();

        // Update bid if applicable
        if ($section->bid_id) {
            $bid = Bid::find($section->bid_id);
            if ($bid) {
                $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
                $bid->save();
            }
        }

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
        $targetEstimateId = $this->estimate->id ?? $estimate_id;
        $currentMaxOrder = EstimateSection::query()
            ->where('estimate_id', $targetEstimateId)
            ->where('order', '<', 999999)
            ->max('order');

        $nextOrder = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;

        $section = EstimateSection::create([
            'estimate_id' => $targetEstimateId,
            'order' => $nextOrder,
            'name' => $name,
            'total' => 0.00,
            'deleted_at' => null,
        ]);

        $this->maybeCreateChangeOrderBid($section);

        return $section;
    }

    public function sectionAdd()
    {
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
        $section = EstimateSection::withTrashed()->findOrFail($sectionId);
        $section->restore();

        $currentMaxOrder = EstimateSection::query()
            ->where('estimate_id', $section->estimate_id)
            ->where('order', '<', 999999)
            ->max('order');

        $section->order = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;
        $section->save();

        // Restore the line items without triggering observers that mutate section totals.
        EstimateLineItem::withoutEvents(function () use ($section) {
            $section->estimate_line_items()->onlyTrashed()->restore();
        });

        $section->total = $section->estimate_line_items()->sum('total');
        $section->save();

        $bid = $section->bid;
        
        // If the section had a bid_id but the bid was deleted, create a new change order bid
        if (! $bid && $section->bid_id) {
            $section->bid_id = null;
            $section->save();
            // Create a new change order bid for this restored section
            $this->maybeCreateChangeOrderBid($section);
            // Refresh section to get updated bid_id
            $section->refresh();
            $bid = $section->bid;
        }
        
        if ($bid) {
            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

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
        $section_data = $this->sections[$section_index];
        $section = EstimateSection::findOrFail($section_data['id']);

        // Push deleted sections to the end so restores can be reinserted cleanly.
        $section->order = 999999;
        $section->save();

        // Disable the section and its line items, but don't mutate stored totals.
        EstimateLineItem::withoutEvents(function () use ($section) {
            $section->estimate_line_items()->delete();
        });

        $section->delete();

        $bid = $section->bid;
        if ($bid) {
            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

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
        $section = EstimateSection::findOrFail($this->sections[$section_index]['id']);
        $section->name = $this->sections[$section_index]['name'];
        $section->save();
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

    protected function maybeCreateChangeOrderBid(EstimateSection $section): void
    {
        $estimate = $section->estimate;
        $project = $estimate?->project;
        $projectId = $estimate?->project_id;
        $vendorId = $estimate?->belongs_to_vendor_id;

        if (! $projectId || ! $vendorId || ! $project) {
            return;
        }

        // Ensure latestStatus is loaded
        $project->loadMissing('latestStatus');

        // Only auto-create change order bids for Active or Service Call projects
        $statusTitle = $project->latestStatus?->title;
        $activeStatuses = ['Active', 'Service Call'];

        if (! in_array($statusTitle, $activeStatuses, true)) {
            return;
        }

        $nextType = (int) (Bid::where('project_id', $projectId)
            ->where('vendor_id', $vendorId)
            ->max('type') ?? 1) + 1;

        $bid = Bid::create([
            'amount' => 0.00,
            'type' => $nextType,
            'project_id' => $projectId,
            'vendor_id' => $vendorId,
        ]);

        $section->bid_id = $bid->id;
        $section->save();
    }

    public function sendInvite(): void
    {
        $this->authorize('update', $this->estimate);

        $estimate = Estimate::withoutGlobalScopes()
            ->with(['signatures', 'project.client.users'])
            ->find($this->estimate->id);

        if (! $estimate) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'warning',
                heading: 'Estimate Not Found',
                text: 'Unable to send invites for this estimate.',
            );

            return;
        }

        if ($estimate->isVendorSigned() && ! $estimate->isFullySigned()) {
            $clientUsers = $estimate->project?->client?->users ?? collect();
            $signedUserIds = $estimate->signatures->pluck('user_id')->all();
            $pendingRecipients = $clientUsers
                ->filter(fn ($user) => filled($user->email) && ! in_array($user->id, $signedUserIds));

            if ($pendingRecipients->isEmpty()) {
                Flux::toast(
                    duration: 5000,
                    position: 'top right',
                    variant: 'warning',
                    heading: 'No Pending Signers',
                    text: 'All client users have signed or are missing an email address.',
                );

                return;
            }

            $sent = 0;
            foreach ($pendingRecipients as $user) {
                Mail::mailer('mailtrap-sdk')->to($user->email)->send(
                    new EstimateSigningInvite($estimate, $user->first_name ?? '')
                );
                $sent++;
            }

            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'success',
                heading: 'Signing Invites Sent',
                text: "Sent {$sent} contract signing invite" . ($sent !== 1 ? 's' : '') . '.',
            );

            return;
        }

        $client = $this->estimate->client;
        $vendorId = $this->estimate->belongs_to_vendor_id;
        $allUsers = $client?->users ?? collect();

        // The registration.registered flag is unreliable on legacy/imported users,
        // so send to every client user with an email and let recipients ignore it
        // if they're already signed up.
        $users = $allUsers->filter(fn ($u) => filled($u->email));

        if ($users->isEmpty()) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'warning',
                heading: 'No Recipients',
                text: 'No client users with an email address.',
            );

            return;
        }

        $sent = 0;
        foreach ($users as $user) {
            if (! $user->email) {
                continue;
            }

            Mail::mailer('mailtrap-sdk')->to($user->email)->send(
                new WelcomeClient($vendorId, $user->first_name ?? '')
            );
            $sent++;
        }

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Invites Sent',
            text: "Sent {$sent} invite" . ($sent !== 1 ? 's' : '') . '.',
        );
    }

    public function disableEstimate()
    {
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
        $section_data = $this->sections[$section_index];
        $section = EstimateSection::findOrFail($section_data['id']);
        $line_items = $this->estimate->estimate_line_items()->where('section_id', $section->id)->get();

        $new_section = $this->create_new_section($section->name.' -Copy');

        //create new estimate section
        foreach ($line_items as $duplicate_section_line) {
            $newLineItem = EstimateLineItem::create([
                'estimate_id' => $this->estimate->id,
                'line_item_id' => $duplicate_section_line->line_item_id,
                'section_id' => $new_section->id,
                'order' => $duplicate_section_line->order,
                'name' => $duplicate_section_line->name,
                'category' => $duplicate_section_line->category,
                'sub_category' => $duplicate_section_line->sub_category,
                'unit_type' => $duplicate_section_line->unit_type,
                'quantity' => $duplicate_section_line->quantity,
                'cost' => $duplicate_section_line->cost,
                'total' => $duplicate_section_line->total,
                'desc' => $duplicate_section_line->desc,
                'notes' => $duplicate_section_line->notes,
            ]);

            // Duplicate allowances
            foreach ($duplicate_section_line->allowances as $allowance) {
                $newLineItem->allowances()->create([
                    'description' => $allowance->description,
                    'amount' => $allowance->amount,
                ]);
            }
        }

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

    public function sort_sections($item, $position): void
    {
        $section = EstimateSection::findOrFail($item);
        $section->move($position);
        $this->estimate_refresh();
    }

    public function sort_line_item($item, $position, $sectionId = null): void
    {
        $line_item = EstimateLineItem::findOrFail($item);

        $sectionId = $sectionId ? (int) $sectionId : null;
        $oldSectionId = $line_item->section_id;

        if ($sectionId && $sectionId !== $oldSectionId) {
            // Cross-section move: close the gap in the old section
            $line_item->displace();

            // Move to the new section
            $line_item->section_id = $sectionId;
            $line_item->save();

            // Refresh the relationship so scopeSortable picks up the new section
            $line_item->unsetRelation('section');

            // Insert at the correct position in the new section
            $line_item->move($position);

            // Recalculate totals on both sections
            $oldSection = EstimateSection::find($oldSectionId);
            $newSection = EstimateSection::find($sectionId);

            foreach ([$oldSection, $newSection] as $section) {
                if (! $section) {
                    continue;
                }

                $section->total = $section->estimate_line_items()->sum('total');
                $section->save();

                if ($section->bid_id) {
                    $bid = Bid::find($section->bid_id);
                    if ($bid) {
                        $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
                        $bid->save();
                    }
                }
            }

            $this->estimate_refresh();
            $this->refreshFinancialIslands();
        } else {
            // Same-section reorder
            $line_item->move($position);
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
