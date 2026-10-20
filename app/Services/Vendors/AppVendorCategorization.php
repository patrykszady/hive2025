<?php

namespace App\Services\Vendors;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The app's Categories screen — the same retail-vendor categorization as
 * the web's /vendors/categories page (App\Livewire\Categories\
 * {CategoriesIndex,VendorCategoryCard}). Also covers what the web's
 * separate /vendor_sheets_type page (App\Livewire\Vendors\
 * VendorSheetsTypeIndex) does — setting a vendor's `sheets_type` — since
 * that is the same field on the same retail-vendor rows this screen already
 * lists; the app exposes it as one "Vendor Categories" screen rather than
 * forking a second list for one extra field. VendorCategoriesCreate (a
 * second, overlapping bulk-assign form, largely dead code — see its own
 * file's commented-out body) is not mirrored.
 */
class AppVendorCategorization
{
    private const PER_PAGE = 25;

    /**
     * Retail vendors, searched/filtered by year — mirrors
     * CategoriesIndex::render() exactly, including its "loadMore" style
     * paging (one extra row fetched past perPage tells hasMore).
     *
     * @return array{vendors: Collection<int, Vendor>, has_more: bool}
     */
    public function vendors(string $search, string $year, int $perPage): array
    {
        $vendors = Vendor::where('business_type', 'Retail')
            ->when($search, fn (Builder $q) => $q->where('business_name', 'like', '%'.$search.'%'))
            ->whereHas('expenses', function (Builder $q) use ($year) {
                if ($year) {
                    $q->whereYear('date', $year);
                }
            })
            ->with('category')
            ->withCount(['expenses as expense_count' => function (Builder $q) use ($year) {
                if ($year) {
                    $q->whereYear('date', $year);
                }
            }])
            ->orderBy('business_name', 'ASC')
            ->limit($perPage)
            ->get();

        return ['vendors' => $vendors, 'has_more' => $vendors->count() === $perPage];
    }

    /**
     * CategoriesIndex::availableYears(), with the year extraction written
     * per-driver so it also runs on the Pest suite's SQLite — MySQL's
     * YEAR(date) (what CategoriesIndex itself calls) has no SQLite
     * equivalent, which would otherwise only surface at test time.
     */
    public function availableYears(): array
    {
        $yearExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', date) AS INTEGER)"
            : 'YEAR(date)';

        return Expense::selectRaw("{$yearExpression} as year")
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (string) $y)
            ->toArray();
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return Category::orderBy('friendly_primary')->orderBy('friendly_detailed')->get();
    }

    /**
     * A retail vendor's expenses grouped by category label — mirrors
     * VendorCategoryCard::vendorExpenses().
     *
     * @return Collection<string, Collection<int, Expense>>
     */
    public function expensesByCategory(Vendor $vendor, string $year): Collection
    {
        return Expense::where('vendor_id', $vendor->id)
            ->when($year, fn (Builder $q) => $q->whereYear('date', $year))
            ->with('category')
            ->orderByDesc('date')
            ->get()
            ->groupBy(fn (Expense $expense) => $expense->category ? $expense->category->friendly_primary : 'Uncategorized');
    }

    public function expenseCount(Vendor $vendor, string $year): int
    {
        return Expense::where('vendor_id', $vendor->id)
            ->when($year, fn (Builder $q) => $q->whereYear('date', $year))
            ->count();
    }

    /** VendorCategoryCard::updateSheetsType() — also the web's VendorSheetsTypeIndex field. */
    public function updateSheetsType(Vendor $vendor, ?string $sheetsType): Vendor
    {
        $vendor->update(['sheets_type' => $sheetsType ?: null]);

        return $vendor;
    }

    /**
     * VendorCategoryCard::updateVendorCategory() — sets the vendor's default
     * category AND recategorizes its expenses in the selected year window.
     *
     * @return array{vendor: Vendor, expense_count: int}
     */
    public function updateCategory(Vendor $vendor, Category $category, string $year): array
    {
        $vendor->update(['category_id' => $category->id]);

        $count = Expense::where('vendor_id', $vendor->id)
            ->when($year, fn (Builder $q) => $q->whereYear('date', $year))
            ->update(['category_id' => $category->id]);

        return ['vendor' => $vendor->refresh(), 'expense_count' => $count];
    }

    /** VendorCategoryCard::clearVendorCategory(). */
    public function clearCategory(Vendor $vendor): Vendor
    {
        $vendor->update(['category_id' => null]);

        return $vendor->refresh();
    }

    /** VendorCategoryCard::reassignExpenseCategory(). $fromCategoryId null reassigns the "Uncategorized" group. */
    public function reassignExpenses(Vendor $vendor, ?int $fromCategoryId, Category $toCategory): int
    {
        return Expense::where('vendor_id', $vendor->id)
            ->when(
                $fromCategoryId === null,
                fn (Builder $q) => $q->whereNull('category_id'),
                fn (Builder $q) => $q->where('category_id', $fromCategoryId),
            )
            ->update(['category_id' => $toCategory->id]);
    }
}
