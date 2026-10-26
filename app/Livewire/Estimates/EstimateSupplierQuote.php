<?php

namespace App\Livewire\Estimates;

use App\Jobs\ReadSupplierQuote;
use App\Livewire\Projects\ProjectFinances;
use App\Models\Estimate;
use App\Models\EstimateSubDocument;
use App\Services\Estimates\SupplierQuotePricer;
use App\Services\Estimates\SupplierQuoteSectionWriter;
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
 * "Add Supplier Quote" on the estimate page: upload a window and door
 * supplier's quote or order (Advanced Window Corp, Climate Solutions…), have
 * it read in the background (ReadSupplierQuote), review every unit with its
 * drawing and the price the homeowner will see — the supplier's LIST price
 * plus this supplier's markup, with the supplier's tax folded into each line,
 * never the discount — and add it all as one section of the estimate
 * (SupplierQuoteSectionWriter). Earlier quotes stay listed.
 */
class EstimateSupplierQuote extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Estimate $estimate;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public ?int $supplierVendorId = null;

    public ?int $documentId = null;

    /** upload | reading | review | failed */
    public string $step = 'upload';

    /** Added to the supplier's list prices, as typed ("15" or "12.5"). */
    public string $markupPercent = '0';

    public string $sectionName = SupplierQuoteSectionWriter::DEFAULT_SECTION_NAME;

    public ?string $error = null;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    /** @return array<string, string> */
    protected function rules(): array
    {
        return ['upload' => 'required|file|mimes:pdf,jpg,jpeg,png,webp,heic|max:15360'];
    }

    #[On('openSupplierQuote')]
    public function openModal(): void
    {
        $this->authorize('update', $this->estimate);

        $this->reset(['upload', 'supplierVendorId', 'documentId', 'error']);
        $this->markupPercent = '0';
        $this->sectionName = SupplierQuoteSectionWriter::DEFAULT_SECTION_NAME;
        $this->step = 'upload';
        unset($this->documents);

        $this->modal('estimate-supplier-quote-modal')->show();
    }

    /** Store the upload and queue the reading; the modal polls until the schedule is ready. */
    public function read(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate();

        $extension = strtolower($this->upload->getClientOriginalExtension() ?: 'bin');
        $path = $this->upload->storeAs('estimate-sub-documents/'.$this->estimate->id, Str::uuid().'.'.$extension, 'local');

        $document = EstimateSubDocument::create([
            'estimate_id' => $this->estimate->id,
            'project_id' => $this->estimate->project_id,
            'belongs_to_vendor_id' => $this->estimate->belongs_to_vendor_id,
            'sub_vendor_id' => $this->supplierVendorId ?: null,
            'uploaded_by_user_id' => auth()->id(),
            'status' => EstimateSubDocument::STATUS_EXTRACTING,
            'kind' => EstimateSubDocument::KIND_QUOTE,
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => mb_substr($this->upload->getClientOriginalName(), 0, 250),
            'mime_type' => $this->upload->getMimeType(),
        ]);

        ReadSupplierQuote::dispatch($document->id);

        $this->documentId = $document->id;
        $this->upload = null;
        $this->error = null;
        $this->step = 'reading';
        unset($this->document, $this->documents);
    }

    /** Polled while reading. */
    public function checkReading(): void
    {
        unset($this->document);
        $document = $this->document;

        if (! $document) {
            $this->step = 'upload';

            return;
        }

        if ($document->status === EstimateSubDocument::STATUS_MATCHED || $document->status === EstimateSubDocument::STATUS_APPLIED) {
            $this->openDocument($document->id);
        } elseif ($document->status === EstimateSubDocument::STATUS_FAILED) {
            $this->step = 'failed';
            $this->error = $document->failure_reason ?: 'The quote could not be read.';
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
        $this->supplierVendorId = $document->sub_vendor_id;
        $this->error = null;

        if ($document->status === EstimateSubDocument::STATUS_APPLIED) {
            $this->markupPercent = self::percent((float) $document->markup_percent);
            $this->sectionName = (string) (data_get($document->applied, 'section_name') ?: SupplierQuoteSectionWriter::DEFAULT_SECTION_NAME);
        } else {
            $this->markupPercent = self::percent(SupplierQuoteSectionWriter::rememberedMarkup($document->sub_vendor_id) ?? 0);
            $this->sectionName = SupplierQuoteSectionWriter::DEFAULT_SECTION_NAME;
        }

        $this->step = 'review';
        unset($this->document);
    }

    /** The supplier picked by hand: kept on the document, and its remembered markup comes up. */
    public function updatedSupplierVendorId(mixed $value): void
    {
        $this->supplierVendorId = is_numeric($value) && (int) $value > 0 ? (int) $value : null;

        if ($document = $this->document) {
            if ($document->status !== EstimateSubDocument::STATUS_APPLIED) {
                $document->update(['sub_vendor_id' => $this->supplierVendorId]);
                $document->items()->update(['sub_vendor_id' => $this->supplierVendorId]);
            }
            unset($this->document);
        }

        $remembered = SupplierQuoteSectionWriter::rememberedMarkup($this->supplierVendorId);
        if ($remembered !== null && $this->document?->status !== EstimateSubDocument::STATUS_APPLIED) {
            $this->markupPercent = self::percent($remembered);
        }
    }

    public function updatedMarkupPercent(mixed $value): void
    {
        $this->markupPercent = is_numeric($value) ? self::percent(min(100, max(0, (float) $value))) : '0';
    }

    /** Write the section. */
    public function addToEstimate(SupplierQuoteSectionWriter $writer): void
    {
        $this->authorize('update', $this->estimate);

        $this->validate([
            'markupPercent' => 'required|numeric|min:0|max:100',
            'sectionName' => 'required|string|max:120',
        ], [], ['markupPercent' => 'markup', 'sectionName' => 'section name']);

        $document = $this->document;

        if (! $document || $document->status !== EstimateSubDocument::STATUS_MATCHED) {
            $this->error = 'This quote has already been added.';

            return;
        }

        $section = $writer->write($document, (float) $this->markupPercent, $this->sectionName);

        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
        $this->modal('estimate-supplier-quote-modal')->close();
        $this->step = 'upload';
        unset($this->document, $this->documents);

        $count = $section->estimate_line_items()->count();

        Flux::toast(
            duration: 6000,
            position: 'top right',
            variant: 'success',
            heading: 'Section added',
            text: sprintf('"%s": %d %s at %s, from %s.', $section->name, $count, Str::plural('line', $count), money($section->total), $document->label()),
        );
    }

    public function startOver(): void
    {
        $this->reset(['upload', 'documentId', 'error']);
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

    /**
     * The open quote priced for the homeowner at the current markup — or, once
     * added, exactly as it was written.
     *
     * @return array{rows: array<int, array<string, mixed>>, list_subtotal: float, markup_total: float, tax_total: float, client_total: float}
     */
    #[Computed]
    public function priced(): array
    {
        $document = $this->document;
        $items = array_values((array) data_get($document?->document, 'items', []));

        if ($document && $document->status === EstimateSubDocument::STATUS_APPLIED) {
            $lines = collect((array) data_get($document->applied, 'lines', []))->keyBy('index');
            $totals = (array) data_get($document->applied, 'totals', []);

            return [
                'rows' => $lines->all(),
                'list_subtotal' => (float) ($totals['list_subtotal'] ?? 0),
                'markup_total' => (float) ($totals['markup_total'] ?? 0),
                'tax_total' => (float) ($totals['tax_total'] ?? 0),
                'client_total' => (float) ($totals['client_total'] ?? 0),
            ];
        }

        $priced = SupplierQuotePricer::price($items, (float) data_get($document?->document, 'tax_amount', 0), (float) $this->markupPercent);
        $priced['rows'] = collect($priced['rows'])->keyBy('index')->all();

        return $priced;
    }

    protected function documentQuery()
    {
        return EstimateSubDocument::query()->where('estimate_id', $this->estimate->id)->where('kind', EstimateSubDocument::KIND_QUOTE);
    }

    /** 15.0 → "15", 12.5 → "12.5". */
    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    public function render(): View
    {
        return view('livewire.estimates.supplier-quote');
    }
}
