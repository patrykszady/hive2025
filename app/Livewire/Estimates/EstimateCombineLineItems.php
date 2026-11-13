<?php

namespace App\Livewire\Estimates;

use App\Livewire\Projects\ProjectFinances;
use App\Models\Estimate;
use App\Services\Estimates\EstimateLineItemCombiner;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Combine Items" on an estimate: the same catalog item priced in several
 * sections — the flooring, the painting of an estimate drafted room by room
 * — picked from a list and made into one line each in a section the
 * estimator names (EstimateLineItemCombiner). The estimate's total does not
 * change, and the dialog shows what each combined line will come to before
 * anything is saved.
 */
class EstimateCombineLineItems extends Component
{
    use AuthorizesRequests;

    public Estimate $estimate;

    /** @var list<string> The items ticked to combine, by the key EstimateLineItemCombiner::candidates() gives each. */
    public array $selected = [];

    public string $sectionName = '';

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    /**
     * @param  list<int>  $tick  catalog items to start ticked — the ones an AI draft just put in several sections
     */
    #[On('openCombineLineItems')]
    public function openModal(array $tick = []): void
    {
        $this->authorize('update', $this->estimate);
        $this->reset('selected', 'sectionName');
        $this->resetErrorBag();
        unset($this->candidates);

        if ($tick !== []) {
            $this->selected = collect($this->candidates)
                ->whereIn('line_item_id', array_map('intval', $tick))
                ->pluck('key')
                ->map(fn ($key) => (string) $key)
                ->values()
                ->all();
            $this->updatedSelected();
        }

        $this->modal('estimate-combine-line-items-modal')->show();
    }

    /**
     * The section's name is suggested from what is ticked while the
     * estimator has not typed one: "Flooring" for flooring items, and
     * "Throughout" once the picks span trades.
     */
    public function updatedSelected(): void
    {
        $this->resetErrorBag();

        $categories = collect($this->candidates)
            ->filter(fn (array $item) => in_array((string) $item['key'], array_map('strval', $this->selected), true))
            ->pluck('category')
            ->filter()
            ->unique()
            ->values();

        $suggestions = collect($this->candidates)->pluck('category')->filter()->push('Throughout')->all();

        if ($this->sectionName === '' || in_array($this->sectionName, $suggestions, true)) {
            $this->sectionName = match ($categories->count()) {
                0 => '',
                1 => (string) $categories->first(),
                default => 'Throughout',
            };
        }
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function candidates(): array
    {
        return app(EstimateLineItemCombiner::class)->candidates($this->estimate);
    }

    public function combine(EstimateLineItemCombiner $combiner): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate(
            ['selected' => 'required|array|min:1', 'sectionName' => 'required|string|max:60'],
            ['selected.required' => 'Tick at least one item to combine.', 'sectionName.required' => 'Name the section the combined items go in.'],
        );

        // Only what this estimate itself can combine: a ticked key is client-held.
        $offered = collect($this->candidates)->pluck('key')->map(fn ($key) => (string) $key)->all();
        $chosen = array_values(array_intersect(array_map('strval', $this->selected), $offered));

        if ($chosen === []) {
            $this->addError('selected', 'Those items can no longer be combined. Close this and open it again.');

            return;
        }

        $result = $combiner->combine($this->estimate, $chosen, $this->sectionName);

        unset($this->candidates);
        $this->modal('estimate-combine-line-items-modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);

        $items = $result['items'];
        $lines = count($result['combined']);

        Flux::toast(
            duration: 8000,
            variant: 'success',
            heading: $items.' '.Str::plural('item', $items).' combined into '.$result['section']->name,
            text: $result['removed'].' lines became '.$lines.'; the estimate total is unchanged.'
                // An item its sections priced differently keeps a line per unit price.
                .($lines > $items ? ' Items priced more than one way kept a line per price.' : '')
                .($result['emptied'] !== [] ? ' Now empty: '.implode(', ', $result['emptied']).'.' : ''),
        );
    }

    public function render(): View
    {
        return view('livewire.estimates.estimate-combine-line-items');
    }
}
