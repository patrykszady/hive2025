<?php

namespace App\Livewire\LineItems;

use App\Livewire\Forms\EstimateLineItemForm;
use App\Livewire\Projects\ProjectFinances;
use App\Models\Bid;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\LineItemAllowance;
use App\Services\AllowanceAggregator;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EstimateLineItemCreate extends Component
{
    use AuthorizesRequests;

    public Estimate $estimate;

    public EstimateLineItemForm $form;

    public $section_id = null;

    public $line_item_id = null;

    public $edit_line_item = false;

    public $estimate_line_item = [];

    public $section_item_count = null;

    public $view_text = [
        'card_title' => 'Add Line Item',
        'button_text' => 'Add Item',
        'form_submit' => 'save',
    ];

    protected $listeners = ['addToEstimate', 'editOnEstimate'];

    public function rules()
    {
        return [
            'line_item_id' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        if ($field === 'line_item_id') {
            $this->selected_line_item($value);
        }

        $this->validateOnly($field);
        if (in_array($field, ['form.quantity', 'form.cost'])) {
            $this->form->total = $this->getTotalLineItemProperty();
        }

        if ($field === 'form.quantity') {
            $this->recalculateAllowanceAmounts();
        }

        if (preg_match('/^form\.allowances\.(\d+)\.(description|unit_amount|pricing_mode)$/', $field, $matches)) {
            $index = (int) $matches[1];

            if ($matches[2] === 'description') {
                $this->fillAllowanceFromHistory($index);
            } elseif ($matches[2] === 'pricing_mode') {
                $this->applyAllowancePricingMode($index);
            } elseif ($matches[2] === 'unit_amount') {
                $unitAmount = $this->form->allowances[$index]['unit_amount'] ?? '';

                if ($unitAmount !== '') {
                    $this->form->allowances[$index]['amount'] = $this->calculateAllowanceAmount($unitAmount);
                }
            }
        }
    }

    #[Computed]
    public function line_items()
    {
        return LineItem::orderBy('created_at', 'DESC')->get()->keyBy('id');
    }

    /**
     * Canonical "like" allowances previously used for the selected line item,
     * with the dominant per-unit price filled in. Selecting one fills the
     * per-unit amount and computes the total from the line item quantity.
     *
     * @return \Illuminate\Support\Collection<int, array{description: string, unit_amount: ?float}>
     */
    #[Computed]
    public function previousAllowances()
    {
        if (! $this->line_item_id) {
            return collect();
        }

        $globalAllowances = LineItemAllowance::query()
            ->where('line_item_id', $this->line_item_id)
            ->orderBy('id')
            ->get();

        if ($globalAllowances->isNotEmpty()) {
            return $globalAllowances
                ->map(fn (LineItemAllowance $allowance) => [
                    'description' => $allowance->description,
                    'pricing_mode' => $allowance->pricing_mode ?? ($allowance->unit_amount !== null ? 'per_unit' : 'lump_sum'),
                    'unit_amount' => $allowance->unit_amount !== null ? (float) $allowance->unit_amount : null,
                    'amount' => $allowance->amount !== null ? (float) $allowance->amount : null,
                ])
                ->filter(fn (array $allowance) => $allowance['description'] !== '')
                ->values();
        }

        $allowances = EstimateLineItemAllowance::query()
            ->whereHas('estimateLineItem', fn ($query) => $query->where('line_item_id', $this->line_item_id))
            ->with('estimateLineItem:id,line_item_id,name,unit_type,quantity')
            ->orderByDesc('id')
            ->get();

        return app(AllowanceAggregator::class)->aggregate($allowances)
            ->map(fn (array $allowance) => [
                'description' => $allowance['description'],
                'pricing_mode' => $allowance['unit_amount'] !== null ? 'per_unit' : 'lump_sum',
                'unit_amount' => $allowance['unit_amount'],
                'amount' => null,
            ])
            ->filter(fn (array $allowance) => $allowance['description'] !== '')
            ->values();
    }

    /**
     * Get allowance suggestions for a specific row, excluding descriptions that
     * are already selected anywhere in the form.
     *
     * @return \Illuminate\Support\Collection<int, array{description: string, pricing_mode: string, unit_amount: ?float, amount: ?float}>
     */
    public function previousAllowancesForRow(int $index)
    {
        $selectedDescriptions = collect($this->form->allowances ?? [])
            ->pluck('description')
            ->map(fn ($description) => trim((string) $description))
            ->filter()
            ->unique()
            ->values();

        if ($selectedDescriptions->isEmpty()) {
            return $this->previousAllowances;
        }

        return $this->previousAllowances
            ->reject(fn (array $allowance) => $selectedDescriptions->contains($allowance['description']))
            ->values();
    }

    /**
     * Fill the per-unit amount (and computed total) for an allowance row when
     * its description matches a previously used canonical allowance.
     */
    protected function fillAllowanceFromHistory(int $index): void
    {
        $description = trim($this->form->allowances[$index]['description'] ?? '');

        if ($description === '') {
            return;
        }

        $match = $this->previousAllowances()
            ->first(fn (array $allowance) => $allowance['description'] === $description);

        if (! $match) {
            return;
        }

        $mode = ($match['pricing_mode'] ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit';

        if ($mode === 'lump_sum') {
            $this->form->allowances[$index]['pricing_mode'] = 'lump_sum';
            $this->form->allowances[$index]['unit_amount'] = '';

            if (($match['amount'] ?? null) !== null) {
                $this->form->allowances[$index]['amount'] = number_format((float) $match['amount'], 2, '.', '');
            }

            return;
        }

        $unitAmount = $match['unit_amount'];

        if ($unitAmount === null) {
            return;
        }

        $this->form->allowances[$index]['pricing_mode'] = 'per_unit';
        $this->form->allowances[$index]['unit_amount'] = number_format((float) $unitAmount, 2, '.', '');
        $this->form->allowances[$index]['amount'] = $this->calculateAllowanceAmount($unitAmount);
    }

    /**
     * Calculate an allowance total from a per-unit amount, borrowing the
     * quantity from the line item (unless the line item has no unit type).
     */
    protected function calculateAllowanceAmount($unitAmount): string
    {
        $quantity = $this->form->unit_type === 'no_unit' ? 1 : (float) ($this->form->quantity ?: 1);
        $amount = (float) $unitAmount * $quantity;

        return number_format($amount, 2, '.', '');
    }

    /**
     * Toggle an allowance between per-unit and lump-sum pricing.
     */
    public function toggleAllowancePerUnit(int $index): void
    {
        $current = $this->form->allowances[$index]['pricing_mode'] ?? 'per_unit';

        $this->form->allowances[$index]['pricing_mode'] = $current === 'lump_sum' ? 'per_unit' : 'lump_sum';

        $this->applyAllowancePricingMode($index);
    }

    /**
     * React to a per-allowance pricing mode change: lump sum clears the
     * per-unit amount (the total is edited directly), while per-unit recomputes
     * the total from the per-unit amount and the line item quantity.
     */
    protected function applyAllowancePricingMode(int $index): void
    {
        $mode = ($this->form->allowances[$index]['pricing_mode'] ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit';

        if ($mode === 'lump_sum') {
            $this->form->allowances[$index]['unit_amount'] = '';

            return;
        }

        $unitAmount = $this->form->allowances[$index]['unit_amount'] ?? '';

        if ($unitAmount === '') {
            $unitAmount = $this->deriveUnitAmountFromTotal($index);
            $this->form->allowances[$index]['unit_amount'] = $unitAmount;
        }

        if ($unitAmount !== '') {
            $this->form->allowances[$index]['amount'] = $this->calculateAllowanceAmount($unitAmount);
        }
    }

    /**
     * Back out a per-unit amount from an allowance's existing total and the
     * line item quantity (e.g. a $105 total over 21 sq.ft. yields $5.00).
     */
    protected function deriveUnitAmountFromTotal(int $index): string
    {
        $amount = (float) ($this->form->allowances[$index]['amount'] ?? 0);
        $quantity = $this->form->unit_type === 'no_unit' ? 1 : (float) ($this->form->quantity ?: 1);

        if ($amount <= 0 || $quantity <= 0) {
            return '';
        }

        return number_format($amount / $quantity, 2, '.', '');
    }

    /**
     * Recalculate every per-unit allowance total when the line item quantity
     * changes. Lump-sum allowances keep their directly-entered total.
     */
    protected function recalculateAllowanceAmounts(): void
    {
        foreach ($this->form->allowances as $index => $allowance) {
            if (($allowance['pricing_mode'] ?? 'per_unit') === 'lump_sum') {
                continue;
            }

            $unitAmount = $allowance['unit_amount'] ?? null;

            if ($unitAmount === null || $unitAmount === '') {
                continue;
            }

            $this->form->allowances[$index]['amount'] = $this->calculateAllowanceAmount($unitAmount);
        }
    }

    public function selected_line_item($line_item_id)
    {
        $this->line_item_id = $line_item_id;
        $this->form->setLineItem($this->line_items[$line_item_id]);
        $this->form->total = $this->getTotalLineItemProperty();
    }

    public function getTotalLineItemProperty()
    {
        // $total = 0;
        // $total +=
        // dd(isset($this->form->quantity));
        if ($this->form->quantity == 0) {
            $quantity = 0;
        } else {
            $quantity = $this->form->quantity;
        }

        if ($this->form->cost == 0) {
            $cost = 0;
        } else {
            $cost = $this->form->cost;
        }

        $total = $quantity * $cost;
        $total = number_format((float) $total, 2, '.', '');

        return $total;
    }

    /**
     * Create an offsetting "Credit" copy of the line item being edited in a
     * change-order section — the way to back a signed (locked) line item out
     * of the contract without touching the original. Uses the form's current
     * amount/quantity, so partial credits work on unlocked items.
     */
    public function creditToChangeOrder()
    {
        $this->authorize('create', LineItem::class);

        if (! $this->edit_line_item || ! $this->estimate_line_item) {
            return;
        }

        $original = $this->estimate_line_item;
        $section = $this->resolveChangeOrderSection();

        $quantity = (float) ($this->form->quantity ?: $original->quantity ?: 1);
        $cost = (float) ($this->form->cost ?: $original->cost);
        $total = number_format($quantity * $cost, 2, '.', '');

        EstimateLineItem::create([
            'estimate_id' => $this->estimate->id,
            'line_item_id' => $original->line_item_id,
            'section_id' => $section->id,
            'name' => 'Credit: '.$original->name,
            'category' => $original->category,
            'sub_category' => $original->sub_category,
            'unit_type' => $original->unit_type,
            'quantity' => $quantity,
            'cost' => -$cost,
            'total' => -$total,
            // The credit doesn't repeat the original scope text — it just
            // points back at the credited line item.
            'desc' => 'Credit for Line Item: '.$original->name,
            'notes' => null,
            'order' => $section->estimate_line_items()->count() + 1,
        ]);

        $this->modal('estimate_line_item_form_modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Credit Added',
            text: money(-$total).' credit for '.$original->name.' added to '.($section->name ?: $section->bid?->name ?: 'Change Order').'.',
        );
    }

    /**
     * The section a credit lands in: the newest unlocked section already
     * attached to a change-order bid, or a fresh "Change Order" section with
     * its own change-order bid (mirrors EstimateShow::maybeCreateChangeOrderBid,
     * but unconditional — crediting is an explicit change-order action).
     */
    protected function resolveChangeOrderSection(): EstimateSection
    {
        $existing = $this->estimate->estimate_sections()
            ->with('bid')
            ->orderByDesc('order')
            ->get()
            ->first(fn ($section) => $section->bid && (int) $section->bid->type >= 2 && ! $section->isLocked());

        if ($existing) {
            return $existing;
        }

        $maxOrder = $this->estimate->estimate_sections()->where('order', '<', 999999)->max('order');

        $section = EstimateSection::create([
            'estimate_id' => $this->estimate->id,
            'order' => is_null($maxOrder) ? 0 : $maxOrder + 1,
            'name' => 'Change Order',
            'total' => 0.00,
        ]);

        $projectId = $this->estimate->project_id;
        $vendorId = $this->estimate->belongs_to_vendor_id;

        if ($projectId && $vendorId) {
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

        return $section;
    }

    public function removeFromEstimate()
    {
        $this->estimate_line_item->delete();
        $this->modal('estimate_line_item_form_modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function editOnEstimate($estimate_line_item_id)
    {
        $this->form->reset();
        $this->estimate_line_item = $this->estimate->estimate_line_items()
            ->with('allowances')
            ->findOrFail($estimate_line_item_id);

        $this->form->setEstimateLineItem($this->estimate_line_item);
        $this->form->total = $this->getTotalLineItemProperty();

        $this->line_item_id = $this->estimate_line_item->line_item_id;

        $this->view_text = [
            'card_title' => 'Edit Line Item',
            'button_text' => 'Edit Item',
            'form_submit' => 'edit',
        ];

        $this->section_id = $this->estimate_line_item->section->id;
        $this->edit_line_item = true;
        $this->modal('estimate_line_item_form_modal')->show();

        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function addToEstimate($section_id)
    {
        $section = $this->estimate->estimate_sections()->findOrFail($section_id);
        $this->section_item_count = $section->estimate_line_items->count();
        $this->edit_line_item = false;
        $this->estimate_line_item = null;
        $this->line_item_id = null;
        $this->form->reset();

        $this->view_text = [
            'card_title' => 'Add Line Item',
            'button_text' => 'Add Item',
            'form_submit' => 'save',
        ];

        $this->section_id = $section->id;

        $this->modal('estimate_line_item_form_modal')->show();
    }

    public function edit()
    {
        $this->form->update();

        $this->modal('estimate_line_item_form_modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function updateGlobalLineItem(): void
    {
        $this->authorize('create', LineItem::class);

        if (! $this->edit_line_item || ! $this->line_item_id) {
            return;
        }

        $this->form->validate();

        $lineItem = LineItem::query()->findOrFail($this->line_item_id);

        $lineItem->update([
            'desc' => $this->form->desc,
            'notes' => $this->form->notes,
            'category' => $this->form->category,
            'sub_category' => $this->form->sub_category,
            'unit_type' => $this->form->unit_type,
            'cost' => $this->form->cost,
        ]);

        Flux::toast(
            duration: 4000,
            position: 'top right',
            variant: 'success',
            heading: 'Global line item updated',
            text: '',
        );
    }

    public function save()
    {
        $this->form->store();

        $this->modal('estimate_line_item_form_modal')->close();
        $this->section_item_count = null;
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function addAllowance(): void
    {
        $mode = $this->form->unit_type && $this->form->unit_type !== 'no_unit' ? 'per_unit' : 'lump_sum';

        $this->form->allowances[] = ['id' => null, 'description' => '', 'pricing_mode' => $mode, 'unit_amount' => '', 'amount' => ''];

        $this->dispatch('allowance-added');
    }

    public function removeAllowance(int $index): void
    {
        unset($this->form->allowances[$index]);
        $this->form->allowances = array_values($this->form->allowances);
    }

    public function render()
    {
        return view('livewire.line-items.estimate-line-item-create');
    }
}
