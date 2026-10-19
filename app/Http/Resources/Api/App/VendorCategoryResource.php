<?php

namespace App\Http\Resources\Api\App;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A retail vendor as the app's Categories screen lists it — mirrors
 * CategoriesIndex's row (business name, category, expense count) and, with
 * withDetail(), VendorCategoryCard's expanded view (expenses grouped by
 * category label).
 *
 * @mixin \App\Models\Vendor
 */
class VendorCategoryResource extends JsonResource
{
    protected bool $detail = false;

    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\Expense>>|null */
    protected $expensesByCategory = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Category>|null */
    protected $availableCategories = null;

    /**
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\Expense>>  $expensesByCategory
     * @param  \Illuminate\Support\Collection<int, \App\Models\Category>  $availableCategories
     */
    public function withDetail($expensesByCategory, $availableCategories): static
    {
        $this->detail = true;
        $this->expensesByCategory = $expensesByCategory;
        $this->availableCategories = $availableCategories;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->resource;

        $row = [
            'id' => $vendor->id,
            'business_name' => $vendor->business_name,
            'sheets_type' => $vendor->sheets_type,
            'category' => $vendor->category ? (new CategoryResource($vendor->category))->toArray($request) : null,
            'expense_count' => $vendor->expense_count ?? null,
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'expenses_by_category' => collect($this->expensesByCategory)->map(fn ($group, string $label) => [
                'label' => $label,
                'expenses' => $group->map(fn ($expense) => [
                    'id' => $expense->id,
                    'amount' => (float) $expense->amount,
                    'date' => $expense->date?->toDateString(),
                    'category_id' => $expense->category_id,
                ])->values()->all(),
            ])->values()->all(),
            'available_categories' => collect($this->availableCategories)->map(fn ($category) => (new CategoryResource($category))->toArray($request))->values()->all(),
        ];
    }
}
