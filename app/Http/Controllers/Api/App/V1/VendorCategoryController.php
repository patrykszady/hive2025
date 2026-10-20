<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ReassignVendorExpenseCategoryRequest;
use App\Http\Requests\Api\App\UpdateVendorCategoryRequest;
use App\Http\Requests\Api\App\UpdateVendorSheetsTypeRequest;
use App\Http\Resources\Api\App\CategoryResource;
use App\Http\Resources\Api\App\VendorCategoryResource;
use App\Models\Category;
use App\Models\Vendor;
use App\Services\Vendors\AppVendorCategorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Categories screen (and the web's separate Vendor Sheet Types
 * page — see App\Services\Vendors\AppVendorCategorization's docblock for
 * why they share one screen here) — mirrors App\Livewire\Categories\
 * {CategoriesIndex,VendorCategoryCard}.
 */
class VendorCategoryController extends Controller
{
    public function __construct(private AppVendorCategorization $categorization)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewOptions', Vendor::class);

        $search = (string) $request->query('search', '');
        $year = (string) $request->query('year', '');
        $perPage = (int) $request->query('per_page', 25);

        ['vendors' => $vendors, 'has_more' => $hasMore] = $this->categorization->vendors($search, $year, $perPage);

        return response()->json([
            'data' => $vendors->map(fn (Vendor $vendor) => (new VendorCategoryResource($vendor))->toArray($request))->values()->all(),
            'has_more' => $hasMore,
            'available_years' => $this->categorization->availableYears(),
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->authorize('viewOptions', Vendor::class);

        return response()->json([
            'data' => $this->categorization->categories()->map(fn (Category $category) => (new CategoryResource($category))->toArray($request))->values()->all(),
        ]);
    }

    public function show(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('viewOptions', Vendor::class);

        $year = (string) $request->query('year', '');
        $vendor->setAttribute('expense_count', $this->categorization->expenseCount($vendor, $year));

        $resource = (new VendorCategoryResource($vendor))->withDetail(
            $this->categorization->expensesByCategory($vendor, $year),
            $this->categorization->categories(),
        );

        return response()->json(['data' => $resource->toArray($request)]);
    }

    public function updateSheetsType(UpdateVendorSheetsTypeRequest $request, Vendor $vendor): JsonResponse
    {
        $updated = $this->categorization->updateSheetsType($vendor, $request->validated()['sheets_type'] ?? null);

        return response()->json(['data' => (new VendorCategoryResource($updated))->toArray($request)]);
    }

    public function updateCategory(UpdateVendorCategoryRequest $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validated();
        $category = Category::findOrFail($data['category_id']);

        $result = $this->categorization->updateCategory($vendor, $category, (string) ($data['year'] ?? ''));

        return response()->json([
            'data' => (new VendorCategoryResource($result['vendor']))->toArray($request),
            'expense_count_updated' => $result['expense_count'],
        ]);
    }

    public function clearCategory(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('viewOptions', Vendor::class);

        $updated = $this->categorization->clearCategory($vendor);

        return response()->json(['data' => (new VendorCategoryResource($updated))->toArray($request)]);
    }

    public function reassign(ReassignVendorExpenseCategoryRequest $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validated();
        $toCategory = Category::findOrFail($data['to_category_id']);

        $count = $this->categorization->reassignExpenses($vendor, $data['from_category_id'] ?? null, $toCategory);

        return response()->json(['data' => ['reassigned' => $count]]);
    }
}
