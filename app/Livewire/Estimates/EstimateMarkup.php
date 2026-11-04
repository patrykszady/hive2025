<?php

namespace App\Livewire\Estimates;

use App\Livewire\Projects\ProjectFinances;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Services\Estimates\EstimateMarkupApplier;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Mark Up Line Items" in an estimate section's menu: one percentage on
 * every line item's unit price in that section — or, with the switch, in
 * every section of the estimate (EstimateMarkupApplier) — with the new total
 * shown before anything is saved.
 */
class EstimateMarkup extends Component
{
    use AuthorizesRequests;

    public Estimate $estimate;

    /** As typed: "10", "12.5", or "-5" to mark down. */
    public string $markupPercent = '';

    /** The section whose menu opened the dialog. */
    public ?int $sectionId = null;

    public string $sectionName = '';

    /** Every section of the estimate instead of the one whose menu opened it. */
    public bool $allSections = false;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    #[On('openEstimateMarkup')]
    public function openModal(?int $section = null): void
    {
        $this->authorize('update', $this->estimate);
        $this->reset('markupPercent', 'allSections');
        $this->resetErrorBag();

        $found = $section ? EstimateSection::query()->where('estimate_id', $this->estimate->id)->find($section) : null;
        $this->sectionId = $found?->id;
        $this->sectionName = $found ? ($found->name ?: 'Unnamed Section') : '';
        $this->allSections = $found === null;
        unset($this->preview, $this->lastMarkup);

        $this->modal('estimate-markup-modal')->show();
    }

    public function updatedMarkupPercent(): void
    {
        $this->resetErrorBag();
        unset($this->preview);
    }

    public function updatedAllSections(): void
    {
        unset($this->preview);
    }

    /** The section the markup applies to, or null for every section. */
    protected function scope(): ?int
    {
        return $this->allSections ? null : $this->sectionId;
    }

    /** @return array{before: float, after: float, lines: int}|null */
    #[Computed]
    public function preview(): ?array
    {
        if (! is_numeric($this->markupPercent) || (float) $this->markupPercent == 0.0) {
            return null;
        }

        return app(EstimateMarkupApplier::class)->preview($this->estimate, (float) $this->markupPercent, $this->scope());
    }

    /**
     * The newest markup that can still be reverted, for the dialog's
     * "Last markup" line.
     *
     * @return array{percent: float, scope: string, at: string, before: float, after: float}|null
     */
    #[Computed]
    public function lastMarkup(): ?array
    {
        $markup = app(EstimateMarkupApplier::class)->latestRevertible($this->estimate->fresh());
        if ($markup === null) {
            return null;
        }

        $section = $markup['section_id'] ? EstimateSection::query()->find($markup['section_id']) : null;

        return [
            'percent' => (float) $markup['percent'],
            'scope' => $markup['section_id'] ? ($section?->name ?: 'Unnamed Section') : 'every section',
            'at' => (string) $markup['at'],
            'before' => (float) $markup['before'],
            'after' => (float) $markup['after'],
        ];
    }

    #[On('openEstimateMarkupRevert')]
    public function openRevert(): void
    {
        $this->openModal();
    }

    public function revert(EstimateMarkupApplier $applier): void
    {
        $this->authorize('update', $this->estimate);

        $result = $applier->revert($this->estimate->fresh(), (int) auth()->id());
        unset($this->lastMarkup, $this->preview);

        $this->modal('estimate-markup-modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);

        Flux::toast(
            heading: 'Reverted the '.($result['percent'] > 0 ? '+' : '').rtrim(rtrim(number_format($result['percent'], 2), '0'), '.').'% markup',
            text: $result['restored'].' '.\Illuminate\Support\Str::plural('line item', $result['restored']).' back to their price before it'
                .($result['skipped'] ? '; '.$result['skipped'].' edited since were left as they are.' : '.'),
            variant: 'success',
        );
    }

    public function apply(EstimateMarkupApplier $applier): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate(['markupPercent' => 'required|numeric'], ['markupPercent.required' => 'Enter a markup percentage.']);

        $result = $applier->apply($this->estimate, (float) $this->markupPercent, (int) auth()->id(), $this->scope());

        unset($this->lastMarkup, $this->preview);
        $this->modal('estimate-markup-modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);

        Flux::toast(
            heading: 'Marked up '.$result['lines'].' line items',
            text: money($result['before']).' → '.money($result['after']),
            variant: 'success',
        );
    }

    public function render(): View
    {
        return view('livewire.estimates.markup');
    }
}
