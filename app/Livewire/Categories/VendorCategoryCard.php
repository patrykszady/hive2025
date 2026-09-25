<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VendorCategoryCard extends Component
{
    use AuthorizesRequests;

    public Vendor $vendor;
    public string $year = '';
    public bool $expanded = false;
    public bool $embedded = false;

    /**
     * Expense count for this vendor computed once by the parent index (a
     * single grouped query for every card) and handed down here — avoids
     * this card running its own COUNT query. Locked: it's server-set state,
     * not a form field.
     */
    #[Locked]
    public ?int $initialExpenseCount = null;

    #[Computed]
    public function expenseCount(): int
    {
        if ($this->initialExpenseCount !== null) {
            return $this->initialExpenseCount;
        }

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

    /**
     * Categorizing is an Admin job on the company's own vendor list, and that
     * list includes shared retail vendors (the reason this page exists), so
     * it cannot use VendorPolicy::update, which refuses shared rows. The card
     * only ever mounts with a vendor from the Admin's scoped list, and the
     * model property is checksummed, so the row itself is already vetted.
     */
    private function authorizeCategorizing(): void
    {
        $this->authorize('viewOptions', Vendor::class);
    }

    public function updateSheetsType(?string $sheetsType): void
    {
        $this->authorizeCategorizing();

        $this->vendor->update(['sheets_type' => $sheetsType ?: null]);

        Flux::toast(
            variant: 'success',
            heading: 'Sheets Type Updated',
            text: "{$this->vendor->name} set to " . ($sheetsType ?: 'General Expenses') . ".",
        );
    }

    public function updateVendorCategory(?string $categoryId): void
    {
        $this->authorizeCategorizing();

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
        $this->authorizeCategorizing();

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
        $this->authorizeCategorizing();

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
