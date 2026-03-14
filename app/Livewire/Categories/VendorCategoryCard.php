<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Vendor;
use Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

class VendorCategoryCard extends Component
{
    public Vendor $vendor;
    public string $year = '';
    public bool $expanded = false;
    public bool $embedded = false;

    #[Computed]
    public function expenseCount(): int
    {
        return Expense::where('vendor_id', $this->vendor->id)
            ->when($this->year, fn ($q) => $q->whereYear('date', $this->year))
            ->count();
    }

    #[Computed]
    public function availableCategories()
    {
        return Category::orderBy('friendly_primary')
            ->orderBy('friendly_detailed')
            ->get();
    }

    #[Computed]
    public function vendorExpenses()
    {
        if (! $this->expanded && ! $this->embedded) {
            return collect();
        }

        return Expense::where('vendor_id', $this->vendor->id)
            ->when($this->year, fn ($q) => $q->whereYear('date', $this->year))
            ->with('category')
            ->orderByDesc('date')
            ->get()
            ->groupBy(fn ($expense) => $expense->category
                ? $expense->category->friendly_primary
                : 'Uncategorized');
    }

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;

        unset($this->vendorExpenses);
    }

    public function loadExpenses(): void
    {
        $this->expanded = true;

        unset($this->vendorExpenses);
    }

    public function updateSheetsType(?string $sheetsType): void
    {
        $this->vendor->update(['sheets_type' => $sheetsType ?: null]);

        Flux::toast(
            variant: 'success',
            heading: 'Sheets Type Updated',
            text: "{$this->vendor->name} set to " . ($sheetsType ?: 'General Expenses') . ".",
        );
    }

    public function updateVendorCategory(?string $categoryId): void
    {
        if (! $categoryId) {
            return;
        }

        $category = Category::findOrFail((int) $categoryId);

        $this->vendor->update(['category_id' => $category->id]);

        $expenseCount = Expense::where('vendor_id', $this->vendor->id)
            ->when($this->year, fn ($q) => $q->whereYear('date', $this->year))
            ->update(['category_id' => $category->id]);

        $this->vendor->refresh();
        unset($this->vendorExpenses);

        Flux::toast(
            variant: 'success',
            heading: 'Category Updated',
            text: "Updated {$this->vendor->name} and {$expenseCount} expenses to {$category->friendly_primary} — {$category->friendly_detailed}.",
        );
    }

    public function clearVendorCategory(): void
    {
        $this->vendor->update(['category_id' => null]);
        $this->vendor->refresh();

        Flux::toast(
            variant: 'success',
            heading: 'Category Cleared',
            text: "Removed default category from {$this->vendor->name}. Existing expenses unchanged.",
        );
    }

    public function reassignExpenseCategory(int $fromCategoryId, int $toCategoryId): void
    {
        $toCategory = Category::findOrFail($toCategoryId);

        $count = Expense::where('vendor_id', $this->vendor->id)
            ->where('category_id', $fromCategoryId)
            ->update(['category_id' => $toCategoryId]);

        unset($this->vendorExpenses);

        Flux::toast(
            variant: 'success',
            heading: 'Expenses Reassigned',
            text: "Moved {$count} expenses to {$toCategory->friendly_primary} — {$toCategory->friendly_detailed}.",
        );
    }

    public function render()
    {
        return view('livewire.categories.vendor-category-card');
    }
}
