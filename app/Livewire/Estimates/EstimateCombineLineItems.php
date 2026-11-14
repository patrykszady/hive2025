<?php

namespace App\Livewire\Estimates;

use App\Livewire\Projects\ProjectFinances;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Services\Estimates\EstimateLineItemCombiner;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Combine Items" on an estimate: the same catalog item priced in several
 * sections — the flooring, the painting of an estimate drafted room by room
 * — picked from a list and made into one line each in a new section the
 * estimator names, or in one of the estimate's own sections picked from a
 * list (EstimateLineItemCombiner). The estimate's total does not
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

    /** Where the combined lines go, picked from one dropdown: 'new' (a section named $sectionName) or the id of a section already on the estimate (2026-11-14). */
    public string $into = 'new';

    /**
     * One catalog item (its global line_item id) to show and combine on its
     * own, or '' for every item (2026-11-14: a drafted estimate offered 28
     * at once, and the estimator wanted just the hardwood).
     */
    public string $only = '';

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    /**
     * @param  list<int>  $tick  catalog items to start ticked — the ones an AI draft just put in several sections
     * @param  int|null  $only  a catalog item to open on by itself, ticked — a line's own "combine this item"
     */
    #[On('openCombineLineItems')]
    public function openModal(array $tick = [], ?int $only = null): void
    {
        $this->authorize('update', $this->estimate);
        $this->reset('selected', 'sectionName', 'only', 'into');
        $this->resetErrorBag();
        unset($this->candidates, $this->shown, $this->itemChoices, $this->sections);

        if ($only !== null && collect($this->candidates)->contains('line_item_id', $only)) {
            $this->only = (string) $only;
            $tick = [$only];
        }

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

        // A section already picked from the list keeps its name in the box.
        if ($this->into === 'new' && ($this->sectionName === '' || in_array($this->sectionName, $suggestions, true))) {
            $this->sectionName = match ($categories->count()) {
                0 => '',
                1 => (string) $categories->first(),
                default => 'Throughout',
            };
        }
    }

    /**
     * One item picked to show on its own: it alone is ticked, so what is
     * combined is only ever what is on screen. Back to every item, the ticks
     * stay as they are.
     */
    public function updatedOnly(): void
    {
        if ($this->only === '') {
            return;
        }

        $this->selected = collect($this->shown)->pluck('key')->map(fn ($key) => (string) $key)->values()->all();
        $this->updatedSelected();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function candidates(): array
    {
        return app(EstimateLineItemCombiner::class)->candidates($this->estimate);
    }

    /**
     * The items listed: every one, or only the lines of the one catalog item
     * picked — matched by its global line_item id, so two items that share a
     * name ("New Fixture Location" under Electrical and under Plumbing) never
     * show as one.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function shown(): array
    {
        if ($this->only === '') {
            return $this->candidates;
        }

        return array_values(array_filter($this->candidates, fn (array $item) => (int) $item['line_item_id'] === (int) $this->only));
    }

    /**
     * The sections that can take the combined lines, in the estimate's
     * order with the number it gives each: every one not held by a
     * signature, those the lines come from included.
     *
     * @return list<array{id: int, name: string, position: int}>
     */
    #[Computed]
    public function sections(): array
    {
        return $this->estimate->estimate_sections()->orderBy('order')->orderBy('id')->get()
            ->values()
            ->map(fn (EstimateSection $section, int $index) => [
                'id' => (int) $section->id,
                'name' => filled($section->name) ? trim((string) $section->name) : 'Unnamed Section',
                'position' => $index + 1,
                'locked' => $section->isLocked(),
            ])
            ->reject(fn (array $section) => $section['locked'])
            ->map(fn (array $section) => ['id' => $section['id'], 'name' => $section['name'], 'position' => $section['position']])
            ->values()
            ->all();
    }

    /** The combobox's 'New section "…"' row: the combined lines go in a new section of the name typed there. */
    public function newSection(): void
    {
        $this->sectionName = trim($this->sectionName);
        $this->into = 'new';
        $this->resetErrorBag(['into', 'sectionName']);
    }

    /**
     * The items the filter offers, one per catalog item, in the list's order.
     *
     * @return list<array{line_item_id: int, label: string}>
     */
    #[Computed]
    public function itemChoices(): array
    {
        return collect($this->candidates)
            ->unique('line_item_id')
            ->map(fn (array $item) => [
                'line_item_id' => (int) $item['line_item_id'],
                'label' => $item['name'].' — '.$item['category'].($item['sub_category'] ? '/'.$item['sub_category'] : ''),
            ])
            ->values()
            ->all();
    }

    public function combine(EstimateLineItemCombiner $combiner): void
    {
        $this->authorize('update', $this->estimate);
        $existing = $this->into !== 'new';
        $this->validate(
            [
                'selected' => 'required|array|min:1',
                'into' => ['required', Rule::in(['new', ...array_map('strval', array_column($this->sections, 'id'))])],
                'sectionName' => $existing ? 'nullable|string|max:60' : 'required|string|max:60',
            ],
            [
                'selected.required' => 'Tick at least one item to combine.',
                'sectionName.required' => 'Name the section the combined items go in.',
                'into.in' => 'That section can no longer take combined items. Pick another.',
            ],
        );

        // Only what this estimate itself can combine, and only what is on screen: a ticked key is client-held.
        $offered = collect($this->shown)->pluck('key')->map(fn ($key) => (string) $key)->all();
        $chosen = array_values(array_intersect(array_map('strval', $this->selected), $offered));

        if ($chosen === []) {
            $this->addError('selected', 'Those items can no longer be combined. Close this and open it again.');

            return;
        }

        try {
            $result = $combiner->combine($this->estimate, $chosen, $this->sectionName, $existing ? (int) $this->into : null);
        } catch (\InvalidArgumentException) {
            // Signed, or removed, since the window opened.
            unset($this->sections);
            $this->addError('into', 'That section can no longer take combined items. Pick another.');

            return;
        }

        unset($this->candidates, $this->shown, $this->itemChoices, $this->sections);
        $this->modal('estimate-combine-line-items-modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
        // An AI draft's review open beneath this window shows the combined lines in place of the drafted ones.
        $this->dispatch('estimate-line-items-combined');

        $items = $result['items'];
        $lines = count($result['combined']);

        Flux::toast(
            duration: 8000,
            variant: 'success',
            heading: $items.' '.Str::plural('item', $items).' combined into '.(filled($result['section']->name) ? $result['section']->name : 'Unnamed Section'),
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
