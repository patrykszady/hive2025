<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRule;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Services\Estimates\EstimateAiRuleWriter;
use App\Services\Estimates\EstimateAiDraftWriter;
use App\Services\Estimates\FloorplanParser;
use App\Services\EstimateAIService;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class EstimateAIGenerator extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Estimate $estimate;

    public ?int $sectionId = null;

    public string $inquiry = '';

    /**
     * The layout files for this draft, one per floor (or basement,
     * 2026-09-30): each is measured and the numbers are added up, with each
     * floor's own kept beside the totals.
     *
     * @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $floorplans = [];

    /** @var list<string> A name per layout, same index as $floorplans ("Floor 1", "Basement"). */
    public array $floorplanLabels = [];

    /** The file picker: whatever it receives joins $floorplans, so a second floor can be added after the first. */
    public $newFloorplans = [];

    public array $generatedItems = [];

    public string $reasoning = '';

    public bool $isGenerating = false;

    public bool $showPreview = false;

    public string $error = '';

    /** The record of this run in the generator's memory. Never client-set. */
    #[Locked]
    public ?int $draftId = null;

    public bool $showRules = false;

    public string $newRule = '';

    /** Running total of the rows streamed so far; not component state. */
    protected float $draftTotal = 0.0;

    protected function rules(): array
    {
        return [
            'inquiry' => 'required|min:10',
            'floorplans.*' => 'file|mimes:pdf,jpg,jpeg,png,csv|max:10240',
            'sectionId' => 'required|exists:estimate_sections,id',
        ];
    }

    public function mount(): void
    {
        // Default to first section if exists
        if ($this->estimate->estimate_sections->isNotEmpty()) {
            $this->sectionId = $this->estimate->estimate_sections->first()->id;
        }
    }

    /** Files picked in the upload box join the list, each named "Floor N" until renamed. */
    public function updatedNewFloorplans(): void
    {
        $this->validate(['newFloorplans.*' => 'file|mimes:pdf,jpg,jpeg,png,csv|max:10240']);

        foreach ((array) $this->newFloorplans as $file) {
            $this->floorplans[] = $file;
            $this->floorplanLabels[] = 'Floor '.count($this->floorplans);
        }

        $this->newFloorplans = [];
    }

    public function removeFloorplan(int $index): void
    {
        unset($this->floorplans[$index], $this->floorplanLabels[$index]);
        $this->floorplans = array_values($this->floorplans);
        $this->floorplanLabels = array_values($this->floorplanLabels);
    }

    #[On('openAIGenerator')]
    public function openModal(?int $sectionId = null): void
    {
        if ($sectionId) {
            $this->sectionId = $sectionId;
        }
        $this->reset(['inquiry', 'floorplans', 'floorplanLabels', 'newFloorplans', 'generatedItems', 'reasoning', 'error', 'showPreview', 'draftId', 'showRules', 'newRule']);
        $this->modal('estimate-ai-generator-modal')->show();
    }

    public function generate(): void
    {
        $this->validate([
            'inquiry' => 'required|min:10',
            'sectionId' => 'required',
        ]);

        $this->authorize('update', $this->estimate);

        $this->isGenerating = true;
        $this->error = '';
        $this->generatedItems = [];
        $this->reasoning = '';
        $this->draftId = null;
        $this->draftTotal = 0.0;
        $result = null;

        // sectionId is a client-writable dropdown value: resolve it through
        // this estimate's own sections so it can't be swapped for another
        // tenant's section id.
        $section = $this->estimate->estimate_sections()->findOrFail($this->sectionId);

        try {
            $floorplanData = $this->floorplans !== [] ? $this->parseFloorplans() : null;

            $service = app(EstimateAIService::class);
            $result = $service->generateEstimate(
                inquiry: $this->inquiry,
                floorplanData: $floorplanData,
                vendorId: $this->estimate->belongs_to_vendor_id ?? 1,
                // Every line lands on the estimate the moment it is drafted.
                onLineItem: fn (array $item, int $index) => $this->draftLine($section, $item),
            );

            $this->reasoning = $result['reasoning'] ?? '';

            if (! $result['success']) {
                $this->error = $result['error'] ?? 'Failed to generate estimate';
            } elseif ($this->generatedItems === []) {
                $this->error = 'Nothing was drafted. Describe the work in more detail and try again.';
            }
        } catch (\Exception $e) {
            $this->error = 'An error occurred: '.$e->getMessage();
        } finally {
            $this->isGenerating = false;
        }

        // Whatever was drafted is on the estimate already, cut off or not.
        if ($this->generatedItems !== []) {
            // The section keeps what was asked for and how the scope was read.
            $section->forceFill(['ai_inquiry' => $this->inquiry, 'ai_scope' => $this->reasoning ?: null])->save();
            $this->recordDraft($section, $result['meta'] ?? []);

            $this->showPreview = true;
            $this->refreshEstimate();
            // Stay here to review the draft, whatever else re-rendered meanwhile.
            $this->modal('estimate-ai-generator-modal')->show();
        }
    }

    /**
     * One drafted item onto the section, then into the table the estimator
     * is watching, with the running total and the status line kept in step.
     *
     * @param  array<string, mixed>  $item
     */
    protected function draftLine(EstimateSection $section, array $item): void
    {
        $line = app(EstimateAIService::class)->applyLineItem($this->estimate, $section, $item);

        if ($line === null) {
            return;
        }

        $row = $this->rowFor($line);
        $this->generatedItems[] = $row;
        $this->draftTotal += $row['total'];
        $count = count($this->generatedItems);

        $this->stream(
            to: 'draft-rows',
            content: view('livewire.estimates.partials.ai-draft-row', ['item' => $row, 'index' => $count - 1, 'editable' => false])->render(),
        );
        $this->stream(to: 'draft-total', content: money($this->draftTotal), replace: true);
        $this->stream(to: 'draft-status', content: "Drafting from your catalog… {$count} line ".($count === 1 ? 'item' : 'items').' so far', replace: true);
    }

    /**
     * The run goes into the generator's memory: what was asked, what came
     * back. What the estimator changes afterwards is read from the section.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function recordDraft(EstimateSection $section, array $meta): void
    {
        $draft = EstimateAiDraft::create([
            'vendor_id' => $this->estimate->belongs_to_vendor_id ?? 1,
            'estimate_id' => $this->estimate->id,
            'section_id' => $section->id,
            'user_id' => auth()->id(),
            'request_id' => $meta['request_id'] ?? null,
            'model' => $meta['model'] ?? null,
            'inquiry' => $this->inquiry,
            'floorplan' => $meta['floorplan'] ?? null,
            'reasoning' => $this->reasoning ?: null,
            'drafted_items' => array_map(fn (array $row) => [
                'estimate_line_item_id' => $row['id'],
                'line_item_id' => $row['line_item_id'],
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                'unit_type' => $row['unit_type'],
                'cost' => $row['cost'],
            ], $this->generatedItems),
            'usage' => $meta['usage'] ?? null,
            'stop_reason' => $meta['stop_reason'] ?? null,
            'status' => EstimateAiDraft::DRAFTED,
        ]);

        $this->draftId = $draft->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(EstimateLineItem $line): array
    {
        return [
            'id' => $line->id,
            'line_item_id' => $line->line_item_id,
            'name' => $line->name,
            'category' => $line->category,
            'sub_category' => $line->sub_category,
            'quantity' => (float) $line->quantity,
            'unit_type' => $line->unit_type,
            'cost' => (float) $line->cost,
            'total' => (float) $line->total,
        ];
    }

    /**
     * A quantity typed in the table is saved to its line straight away; a
     * blank or zero counts as one. A browser that sends the whole list at
     * once ($key null — 2026-10-01 on production, after rows had shifted)
     * changes nothing: the rows are re-read from the estimate instead.
     */
    public function updatedGeneratedItems(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            $this->reloadDraftLines();

            return;
        }

        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field !== 'quantity' || ! isset($this->generatedItems[$index])) {
            return;
        }

        $this->authorize('update', $this->estimate);

        $line = $this->estimate->estimate_line_items()->find($this->generatedItems[$index]['id']);
        if ($line === null) {
            return;
        }

        $quantity = is_numeric($value) && (float) $value > 0 ? (float) $value : 1.0;
        $line->quantity = $quantity;
        $line->total = $quantity * (float) $line->cost;
        $line->save();

        $this->generatedItems[$index]['total'] = (float) $line->total;
        $this->refreshEstimate();
    }

    /**
     * The button passes its line's id as well as its position, and the id
     * wins: removing a line shifts every later position, and this deletes
     * for good (2026-09-30: totals and quantities landing on the next line).
     */
    public function removeItem(int $index, ?int $lineId = null): void
    {
        if ($lineId !== null) {
            $index = array_search($lineId, array_map('intval', array_column($this->generatedItems, 'id')), true);
        }

        if ($index === false || ! isset($this->generatedItems[$index])) {
            return;
        }

        $this->authorize('update', $this->estimate);

        // Never wanted: gone for good, not parked among the restorable lines.
        $this->estimate->estimate_line_items()->find($this->generatedItems[$index]['id'])?->forceDelete();

        unset($this->generatedItems[$index]);
        $this->generatedItems = array_values($this->generatedItems);
        $this->refreshEstimate();
    }

    /** Every drafted line off the estimate, and back to the description. */
    public function discardDraft(): void
    {
        $this->authorize('update', $this->estimate);

        app(EstimateAiDraftWriter::class)->discard(
            $this->estimate,
            $this->sectionId,
            $this->draftId,
            array_column($this->generatedItems, 'id'),
        );

        $this->reset(['generatedItems', 'reasoning', 'error', 'showPreview', 'draftId']);
        $this->refreshEstimate();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            heading: 'Draft discarded',
            text: 'The drafted line items were removed from the estimate.',
        );
    }

    /** The draft is on the estimate already; this closes up. */
    public function finish(): void
    {
        $this->authorize('update', $this->estimate);

        $count = count($this->generatedItems);
        $section = $this->estimate->estimate_sections()->find($this->sectionId);

        EstimateAiDraft::whereKey($this->draftId)->where('estimate_id', $this->estimate->id)->update(['status' => EstimateAiDraft::FINISHED]);

        $this->modal('estimate-ai-generator-modal')->close();
        $this->refreshEstimate();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'AI Estimate Applied',
            text: $count.' line '.($count === 1 ? 'item' : 'items').' added to '.($section?->name ?: 'section'),
        );

        $this->reset(['inquiry', 'floorplans', 'floorplanLabels', 'newFloorplans', 'generatedItems', 'reasoning', 'error', 'showPreview', 'draftId']);
    }

    // ---- The company's estimating rules: read on every draft, written here. ----

    public function addRule(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate(['newRule' => 'required|string|min:5|max:500']);

        app(EstimateAiRuleWriter::class)->add($this->vendorId(), $this->newRule, auth()->id());

        $this->newRule = '';
    }

    public function approveRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);
        $rule && app(EstimateAiRuleWriter::class)->approve($rule);
    }

    public function dismissRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);
        $rule && app(EstimateAiRuleWriter::class)->dismiss($rule);
    }

    public function deleteRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);
        $rule && app(EstimateAiRuleWriter::class)->delete($rule);
    }

    protected function rule(int $ruleId): ?EstimateAiRule
    {
        return app(EstimateAiRuleWriter::class)->forVendor($this->vendorId(), $ruleId);
    }

    protected function vendorId(): int
    {
        return (int) ($this->estimate->belongs_to_vendor_id ?? 1);
    }

    /** The edit modal saved or removed a line: re-read the drafted rows. */
    #[On('estimate-line-item-saved')]
    public function reloadDraftLines(): void
    {
        if ($this->generatedItems === []) {
            return;
        }

        $lines = $this->estimate->estimate_line_items()->whereIn('id', array_column($this->generatedItems, 'id'))->get()->keyBy('id');

        $this->generatedItems = collect($this->generatedItems)
            ->filter(fn (array $row) => $lines->has($row['id']))
            ->map(fn (array $row) => $this->rowFor($lines->get($row['id'])))
            ->values()
            ->all();
    }

    protected function refreshEstimate(): void
    {
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to('projects.project-finances');
    }


    /**
     * Every layout measured; one is returned as it is, several are added up
     * (combineFloorplans) with each floor's own numbers kept. Delegates to
     * App\Services\Estimates\FloorplanParser, shared with the Hive
     * Contractors app's floorplan upload (EstimateAiController).
     */
    protected function parseFloorplans(): ?array
    {
        return app(FloorplanParser::class)->parseFloorplans($this->floorplans, $this->floorplanLabels);
    }

    /**
     * Several layouts as one — delegates to FloorplanParser::combineFloorplans().
     * Kept as a public static wrapper: tests call it directly on this class.
     *
     * @param  list<array{label: string, data: array<string, mixed>}>  $layouts
     * @return array<string, mixed>
     */
    public static function combineFloorplans(array $layouts): array
    {
        return FloorplanParser::combineFloorplans($layouts);
    }

    protected function parseFloorplanFile(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file): ?array
    {
        return app(FloorplanParser::class)->parseFloorplanFile($file);
    }

    protected function extractFloorplanMetrics(string $text): array
    {
        return app(FloorplanParser::class)->extractFloorplanMetrics($text);
    }

    protected function extractCsvFloorplanMetrics(string $contents): array
    {
        return app(FloorplanParser::class)->extractCsvFloorplanMetrics($contents);
    }

    protected function isPolycamFormat(array $normalizedHeaders): bool
    {
        return app(FloorplanParser::class)->isPolycamFormat($normalizedHeaders);
    }

    protected function extractPolycamMetrics(array $rows): array
    {
        return app(FloorplanParser::class)->extractPolycamMetrics($rows);
    }

    protected function parsePolycamDimensions(string $value): ?array
    {
        return app(FloorplanParser::class)->parsePolycamDimensions($value);
    }

    protected function parsePolycamValue(string $value): ?float
    {
        return app(FloorplanParser::class)->parsePolycamValue($value);
    }

    protected function parsePolycamFeetInches(string $value): ?float
    {
        return app(FloorplanParser::class)->parsePolycamFeetInches($value);
    }

    protected function parseCsvRows(string $contents): array
    {
        return app(FloorplanParser::class)->parseCsvRows($contents);
    }

    protected function normalizeCsvHeader(string $value): string
    {
        return app(FloorplanParser::class)->normalizeCsvHeader($value);
    }

    protected function findCsvColumn(array $headers, array $tokens): ?int
    {
        return app(FloorplanParser::class)->findCsvColumn($headers, $tokens);
    }

    protected function parseCsvNumber($value): ?float
    {
        return app(FloorplanParser::class)->parseCsvNumber($value);
    }

    protected function extractLabeledSqft(string $text, array $labels): ?float
    {
        return app(FloorplanParser::class)->extractLabeledSqft($text, $labels);
    }

    protected function extractLargestDimensions(string $text): ?array
    {
        return app(FloorplanParser::class)->extractLargestDimensions($text);
    }

    protected function estimateWallSqft(float $length, float $width, int $height): float
    {
        return app(FloorplanParser::class)->estimateWallSqft($length, $width, $height);
    }

    public function getEstimatedTotalProperty(): float
    {
        return array_sum(array_map(fn (array $row) => (float) ($row['total'] ?? 0), $this->generatedItems));
    }

    public function render()
    {
        $sections = $this->estimate->estimate_sections;
        $rules = EstimateAiRule::query()->forVendor($this->vendorId())->whereIn('status', [EstimateAiRule::ACTIVE, EstimateAiRule::PROPOSED])->orderBy('id')->get();

        return view('livewire.estimates.estimate-ai-generator', [
            'sections' => $sections,
            'sectionName' => $sections->firstWhere('id', $this->sectionId)?->name ?: 'Unnamed Section',
            'estimatedTotal' => $this->getEstimatedTotalProperty(),
            'activeRules' => $rules->where('status', EstimateAiRule::ACTIVE)->values(),
            'proposedRules' => $rules->where('status', EstimateAiRule::PROPOSED)->values(),
        ]);
    }
}
