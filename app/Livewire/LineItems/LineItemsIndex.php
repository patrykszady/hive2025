<?php

namespace App\Livewire\LineItems;

use App\Models\LineItem;
use App\Services\LineItems\InlineAllowanceResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class LineItemsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public string $search = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function line_items()
    {
        // LineItem carries no tenant scope of its own: this catalog must
        // never surface another vendor's line items.
        return LineItem::query()
            ->where('belongs_to_vendor_id', auth()->user()->vendor?->id ?? 0)
            ->with(['allowances' => fn ($query) => $query->orderBy('id')])
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('desc', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            })
            ->latest()
            ->paginate(15);
    }

    /**
     * Inline allowances to render beneath each visible line item.
     * Prefer the curated global catalog, and fall back to historical
     * estimate-derived canonical allowances when globals are empty — see
     * App\Services\LineItems\InlineAllowanceResolver, shared with the Hive
     * Contractors app's LineItemController.
     *
     * @return Collection<int, Collection<int, array{description: string, pricing_mode: string, unit_amount: ?float, amount: ?float, unit_type: ?string}>>
     */
    #[Computed]
    public function inlineAllowances(): Collection
    {
        return app(InlineAllowanceResolver::class)->resolve($this->line_items->getCollection());
    }

    #[Title('Line Items')]
    public function render()
    {
        $this->authorize('viewAny', LineItem::class);

        return view('livewire.line-items.index');
    }
}
