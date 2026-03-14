<?php

namespace App\Livewire\Categories;

use App\Models\Vendor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Vendor Categories')]
class CategoriesIndex extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $year = '';

    public int $perPage = 25;

    #[Computed]
    public function availableYears(): array
    {
        return \App\Models\Expense::selectRaw('YEAR(date) as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (string) $y)
            ->toArray();
    }

    public function updatedSearch(): void
    {
        $this->perPage = 25;
    }

    public function updatedYear(): void
    {
        $this->perPage = 25;
    }

    public function loadMore(): void
    {
        $this->perPage += 25;
    }

    public function render()
    {
        $vendors = Vendor::where('business_type', 'Retail')
            ->when($this->search, fn ($q) => $q->where('business_name', 'like', '%' . $this->search . '%'))
            ->whereHas('expenses', function ($q) {
                if ($this->year) {
                    $q->whereYear('date', $this->year);
                }
            })
            ->with('category')
            ->orderBy('business_name', 'ASC')
            ->limit($this->perPage)
            ->get();

        $hasMore = $vendors->count() === $this->perPage;

        return view('livewire.categories.index', [
            'vendors' => $vendors,
            'hasMore' => $hasMore,
        ]);
    }
}
