<?php

namespace App\Livewire\Estimates;

use App\Jobs\AnalyzeEstimateSubDocument;
use App\Livewire\Projects\ProjectFinances;
use App\Models\Bid;
use App\Models\Estimate;
use App\Models\EstimateSubDocument;
use App\Models\LineItem;
use App\Models\SubItemAlias;
use App\Services\Estimates\SubDocumentChangeOrderApplier;
use App\Services\Estimates\SubItemMemory;
use App\Services\LineItems\LineItemWriter;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * "Compare sub invoice" on the estimate page: upload a subcontractor's
 * updated estimate or invoice (PDF, photo or spreadsheet), have it read and
 * matched against the estimate (AnalyzeEstimateSubDocument), then tick the
 * proposed additions and credits and write them into the change order
 * (SubDocumentChangeOrderApplier). Earlier comparisons stay listed so a
 * sub's invoices can be checked one after another.
 */
class EstimateSubDocumentCompare extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public const KINDS_APPLICABLE = ['increase', 'decrease', 'new', 'missing'];

    public Estimate $estimate;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public ?int $subVendorId = null;

    public ?int $documentId = null;

    /** upload | analyzing | review | failed */
    public string $step = 'upload';

    /** @var list<array<string, mixed>> the proposals, as edited */
    public array $rows = [];

    public ?string $error = null;

    /** The row a new catalog item is being created for, and its form. */
    public ?int $newItemRow = null;

    /** @var array{template_id: ?int, name: string, desc: string, category: string, sub_category: string, unit_type: string, cost: string} */
    public array $newItem = self::EMPTY_NEW_ITEM;

    public const EMPTY_NEW_ITEM = ['template_id' => null, 'name' => '', 'desc' => '', 'category' => '', 'sub_category' => '', 'unit_type' => 'pieces', 'cost' => ''];

    public const UNIT_TYPES = ['no_unit', 'pieces', 'sq.ft.', 'li.ft.', 'hours'];

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    /** @return array<string, string> */
    protected function rules(): array
    {
        return ['upload' => 'required|file|mimes:pdf,jpg,jpeg,png,webp,heic,xlsx,xls,csv|max:15360'];
    }

    #[On('openSubDocumentCompare')]
    public function openModal(): void
    {
        $this->authorize('update', $this->estimate);

        $this->reset(['upload', 'subVendorId', 'documentId', 'rows', 'error']);
        $this->step = 'upload';
        unset($this->documents);

        $this->modal('estimate-sub-document-modal')->show();
    }

    /** Store the upload and queue the reading; the modal polls until it is matched. */
    public function analyze(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate();

        $extension = strtolower($this->upload->getClientOriginalExtension() ?: 'bin');
        $path = $this->upload->storeAs('estimate-sub-documents/'.$this->estimate->id, Str::uuid().'.'.$extension, 'local');

        $document = EstimateSubDocument::create([
            'estimate_id' => $this->estimate->id,
            'project_id' => $this->estimate->project_id,
            'belongs_to_vendor_id' => $this->estimate->belongs_to_vendor_id,
            'sub_vendor_id' => $this->subVendorId ?: null,
            'uploaded_by_user_id' => auth()->id(),
            'status' => EstimateSubDocument::STATUS_EXTRACTING,
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => mb_substr($this->upload->getClientOriginalName(), 0, 250),
            'mime_type' => $this->upload->getMimeType(),
        ]);

        AnalyzeEstimateSubDocument::dispatch($document->id);

        $this->documentId = $document->id;
        $this->upload = null;
        $this->error = null;
        $this->step = 'analyzing';
        unset($this->document, $this->documents);
    }

    /** Polled while analyzing. */
    public function checkAnalysis(): void
    {
        unset($this->document);
        $document = $this->document;

        if (! $document) {
            $this->step = 'upload';

            return;
        }

        if ($document->status === EstimateSubDocument::STATUS_MATCHED) {
            $this->openDocument($document->id);
        } elseif ($document->status === EstimateSubDocument::STATUS_FAILED) {
            $this->step = 'failed';
            $this->error = $document->failure_reason ?: 'The document could not be read.';
        }
    }

    public function openDocument(int $documentId): void
    {
        $this->authorize('update', $this->estimate);

        $document = $this->documentQuery()->whereIn('status', [EstimateSubDocument::STATUS_MATCHED, EstimateSubDocument::STATUS_APPLIED])->find($documentId);

        if (! $document) {
            return;
        }

        $this->documentId = $document->id;
        $this->subVendorId = $document->sub_vendor_id;
        $this->rows = array_values((array) data_get($document->proposals, 'rows', []));
        $this->error = null;
        $this->step = 'review';
        unset($this->document, $this->appliedOutcomes);
    }

    public function updatedSubVendorId(mixed $value): void
    {
        $this->subVendorId = is_numeric($value) && (int) $value > 0 ? (int) $value : null;

        if ($document = $this->document) {
            $document->update(['sub_vendor_id' => $this->subVendorId]);
            $document->items()->update(['sub_vendor_id' => $this->subVendorId]);
            unset($this->document);
        }
    }

    /** A row's quantity, cost, catalog item or tick changed: keep its amount and kind right. */
    public function updatedRows(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);
        $index = (int) $index;

        if (! isset($this->rows[$index]) || ! $field) {
            return;
        }

        $row = &$this->rows[$index];

        if ($field === 'line_item_id') {
            $catalogItem = $this->catalog->firstWhere('id', (int) $value);

            if ($catalogItem) {
                $row['line_item_id'] = $catalogItem->id;
                $row['name'] = $catalogItem->name;
                $row['category'] = $catalogItem->category;
                $row['sub_category'] = $catalogItem->sub_category;
                $row['unit_type'] = $catalogItem->unit_type;
                $row['cost'] = round((float) $catalogItem->cost, 2);
                $row['quantity'] = (float) ($row['quantity'] ?: ($row['document_quantity'] ?: 1));
                $row['kind'] = 'new';
                $row['include'] = true;
                $row['learned'] = true;

                // Remembered the moment it's picked: next time this wording is matched before the model is asked.
                foreach ((array) ($row['document_descriptions'] ?? []) as $description) {
                    app(SubItemMemory::class)->remember((int) $this->estimate->belongs_to_vendor_id, $this->subVendorId, (string) $description, $catalogItem->id, SubItemAlias::SOURCE_MANUAL, auth()->id());
                }
                if ($this->documentId && ! empty($row['document_items'])) {
                    $this->document?->items()->whereIn('index', (array) $row['document_items'])->update(['line_item_id' => $catalogItem->id, 'match_source' => 'manual']);
                }
            }
        }

        $row['quantity'] = max(0, round((float) ($row['quantity'] ?? 0), 2));
        $row['cost'] = max(0, round((float) ($row['cost'] ?? 0), 2));

        // A "review" row the admin gives a quantity becomes an addition of its catalog item.
        if ($row['kind'] === 'review' && ! empty($row['line_item_id']) && $row['quantity'] > 0) {
            $row['kind'] = ! empty($row['estimate_numbers']) ? 'increase' : 'new';
            $row['include'] = true;
        }

        $row['amount'] = round((in_array($row['kind'], ['decrease', 'missing'], true) ? -1 : 1) * $row['quantity'] * $row['cost'], 2);
        $row['include'] = (bool) ($row['include'] ?? false) && in_array($row['kind'], self::KINDS_APPLICABLE, true);
    }

    /**
     * The sub lists work we have no catalog item for: start one from the
     * sub's line (a template copies an existing item's category, unit, cost
     * and description).
     */
    public function startNewItem(int $index): void
    {
        $this->authorize('create', LineItem::class);

        $row = $this->rows[$index] ?? null;

        if (! $row) {
            return;
        }

        $description = (string) ($row['document_descriptions'][0] ?? $row['name'] ?? '');

        $this->newItemRow = $index;
        $this->newItem = [
            'template_id' => null,
            'name' => Str::title(trim($description)),
            'desc' => Str::ucfirst(trim($description)).'.',
            'category' => (string) (data_get($this->document?->proposals, 'trades.0') ?? ''),
            'sub_category' => '',
            'unit_type' => ($row['document_quantity'] ?? null) !== null ? 'pieces' : 'no_unit',
            'cost' => '',
        ];
        $this->resetValidation();
    }

    /** A template: its category, unit, cost and description come across; the name stays the sub's unless blank. */
    public function updatedNewItemTemplateId(mixed $value): void
    {
        $template = $this->catalog->firstWhere('id', (int) $value);

        if (! $template) {
            return;
        }

        $full = LineItem::query()->find($template->id);
        $this->newItem['template_id'] = $template->id;
        $this->newItem['category'] = (string) $template->category;
        $this->newItem['sub_category'] = (string) $template->sub_category;
        $this->newItem['unit_type'] = (string) $template->unit_type;
        $this->newItem['cost'] = number_format((float) $template->cost, 2, '.', '');
        $this->newItem['desc'] = (string) ($full?->desc ?: $this->newItem['desc']);
        $this->newItem['name'] = trim($this->newItem['name']) !== '' ? $this->newItem['name'] : (string) $template->name;
    }

    public function cancelNewItem(): void
    {
        $this->newItemRow = null;
        $this->newItem = self::EMPTY_NEW_ITEM;
        $this->resetValidation();
    }

    /** Create the catalog item (the same rules as the catalog form) and match the row to it. */
    public function saveNewItem(): void
    {
        $this->authorize('create', LineItem::class);

        $index = $this->newItemRow;

        if ($index === null || ! isset($this->rows[$index])) {
            return;
        }

        $this->validate([
            'newItem.name' => 'required|min:3',
            'newItem.desc' => 'required|min:3',
            'newItem.category' => 'required|min:3',
            'newItem.sub_category' => 'nullable|min:3',
            'newItem.unit_type' => 'required|in:'.implode(',', self::UNIT_TYPES),
            'newItem.cost' => 'required|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
        ], [], ['newItem.name' => 'name', 'newItem.desc' => 'description', 'newItem.category' => 'category', 'newItem.sub_category' => 'sub category', 'newItem.unit_type' => 'unit type', 'newItem.cost' => 'our cost']);

        // LineItemObserver stamps belongs_to_vendor_id from the signed-in admin's company.
        $created = app(LineItemWriter::class)->create([
            'name' => trim($this->newItem['name']),
            'desc' => trim($this->newItem['desc']),
            'notes' => null,
            'category' => trim($this->newItem['category']),
            'sub_category' => trim($this->newItem['sub_category']) !== '' ? trim($this->newItem['sub_category']) : null,
            'unit_type' => $this->newItem['unit_type'],
            'cost' => number_format((float) $this->newItem['cost'], 2, '.', ''),
        ], []);

        unset($this->catalog);
        $this->rows[$index]['line_item_id'] = $created->id;
        // Matched like a pick: the row becomes an addition and the sub's wording is remembered.
        $this->updatedRows($created->id, $index.'.line_item_id');
        $this->cancelNewItem();

        Flux::toast(variant: 'success', heading: 'Catalog item created', text: $created->name.' is now in your catalog and matched to this line.');
    }

    public function setAll(bool $include): void
    {
        foreach ($this->rows as &$row) {
            // "All" means the changes the sub's document shows, not the possible credits.
            $row['include'] = $include && in_array($row['kind'], ['increase', 'decrease', 'new'], true) && ! empty($row['line_item_id']);
        }
    }

    /** Write the ticked rows into the change order. */
    public function apply(SubDocumentChangeOrderApplier $applier): void
    {
        $this->authorize('update', $this->estimate);

        $document = $this->document;

        if (! $document || $document->status !== EstimateSubDocument::STATUS_MATCHED) {
            return;
        }

        $ticked = array_values(array_filter($this->rows, fn (array $row) => ! empty($row['include'])
            && in_array($row['kind'], self::KINDS_APPLICABLE, true)
            && (float) ($row['quantity'] ?? 0) > 0
            && ! empty($row['line_item_id'])));

        if ($ticked === []) {
            $this->error = 'Tick at least one change to add.';

            return;
        }

        // The rows as edited stay with the document, so the applied view shows what was decided.
        $document->update(['proposals' => array_merge((array) $document->proposals, ['rows' => array_values($this->rows)])]);

        $result = $applier->apply($document, $ticked, auth()->id());
        $applier->confirmUndisputed($document, $this->rows, auth()->id());

        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
        $this->modal('estimate-sub-document-modal')->close();
        $this->step = 'upload';
        unset($this->document, $this->documents);

        Flux::toast(
            duration: 6000,
            position: 'top right',
            variant: 'success',
            heading: 'Change order updated',
            text: sprintf('%d added, %d adjusted, %d credited%s from %s.', count($result['added']), count($result['adjusted'] ?? []), count($result['credited']),
                $result['skipped'] !== [] ? ', '.count($result['skipped']).' skipped' : '', $document->label()),
        );
    }

    public function startOver(): void
    {
        $this->reset(['upload', 'documentId', 'rows', 'error']);
        $this->step = 'upload';
        unset($this->documents);
    }

    #[Computed]
    public function document(): ?EstimateSubDocument
    {
        return $this->documentId ? $this->documentQuery()->with('subVendor')->find($this->documentId) : null;
    }

    /** @return Collection<int, EstimateSubDocument> */
    #[Computed]
    public function documents(): Collection
    {
        return $this->documentQuery()->with('subVendor')->latest('id')->limit(10)->get();
    }

    /** @return Collection<int, LineItem> */
    #[Computed]
    public function catalog(): Collection
    {
        return LineItem::query()
            ->where('belongs_to_vendor_id', $this->estimate->belongs_to_vendor_id)
            ->orderBy('category')->orderBy('name')
            ->get(['id', 'name', 'category', 'sub_category', 'unit_type', 'cost']);
    }

    /** What this sub has bid on the project so far (base bid plus change orders), or null without a sub. */
    #[Computed]
    public function subBidsTotal(): ?float
    {
        if (! $this->subVendorId || ! $this->estimate->project_id) {
            return null;
        }

        $bids = Bid::withoutGlobalScopes()->where('project_id', $this->estimate->project_id)->where('vendor_id', $this->subVendorId);

        return $bids->exists() ? (float) $bids->sum('amount') : null;
    }

    /**
     * What is ticked for the change order — or, once applied, what was
     * written (from the document's recorded outcomes).
     *
     * @return array{count: int, adds: float, credits: float, net: float}
     */
    #[Computed]
    public function totals(): array
    {
        $document = $this->document;

        if ($document && $document->status === EstimateSubDocument::STATUS_APPLIED) {
            $amounts = array_map(fn ($o) => (float) ($o['amount'] ?? 0), $this->appliedOutcomes);
        } else {
            $ticked = array_filter($this->rows, fn (array $row) => ! empty($row['include']) && in_array($row['kind'], self::KINDS_APPLICABLE, true));
            $amounts = array_map(fn ($r) => (float) ($r['amount'] ?? 0), $ticked);
        }

        $adds = array_sum(array_filter($amounts, fn ($a) => $a > 0));
        $credits = array_sum(array_filter($amounts, fn ($a) => $a < 0));

        return ['count' => count($amounts), 'adds' => round($adds, 2), 'credits' => round($credits, 2), 'net' => round($adds + $credits, 2)];
    }

    /**
     * What happened to each row of the open document, once applied (rebuilt
     * for documents applied before it was recorded).
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function appliedOutcomes(): array
    {
        $document = $this->document;

        return $document && $document->status === EstimateSubDocument::STATUS_APPLIED
            ? app(SubDocumentChangeOrderApplier::class)->outcomes($document)
            : [];
    }

    protected function documentQuery()
    {
        return EstimateSubDocument::query()->where('estimate_id', $this->estimate->id);
    }

    public function render(): View
    {
        return view('livewire.estimates.sub-document-compare');
    }
}
