<?php

namespace App\Livewire\LineItems;

use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Services\AllowanceAggregator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LineItemsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'items')]
    public string $tab = 'items';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->resetPage('allowancesPage');
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->resetPage('allowancesPage');
    }

    #[Computed]
    public function line_items()
    {
        return LineItem::query()
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
     * All allowances ever used on estimate line items, collapsed into canonical
     * "like" allowances (one row per global line item + concept) with the
     * dominant per-unit price. Totals are intentionally omitted.
     */
    #[Computed]
    public function allowances(): LengthAwarePaginator
    {
        $allowances = EstimateLineItemAllowance::query()
            ->with(['estimateLineItem:id,line_item_id,name,unit_type,quantity', 'estimateLineItem.line_item:id,name'])
            ->whereHas('estimateLineItem', fn ($query) => $query->whereNotNull('line_item_id'))
            ->orderByDesc('id')
            ->get();

        $rows = app(AllowanceAggregator::class)->aggregate($allowances);

        if ($this->search !== '') {
            $term = Str::lower($this->search);

            $rows = $rows->filter(fn (array $row) => str_contains(Str::lower($row['description']), $term)
                || str_contains(Str::lower((string) $row['line_item_name']), $term))->values();
        }

        $rows = $rows->sortBy(fn (array $row) => [$row['line_item_name'], $row['description']])->values();

        return $this->paginateCollection($rows, 15, 'allowancesPage');
    }

    /**
     * Build a length-aware paginator from an in-memory collection.
     */
    protected function paginateCollection(Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    #[Title('Line Items')]
    public function render()
    {
        $this->authorize('viewAny', LineItem::class);

        return view('livewire.line-items.index');
    }
}
