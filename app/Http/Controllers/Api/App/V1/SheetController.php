<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Sheet;
use App\Services\Sheets\MonthlyTrend;
use App\Services\Sheets\ProfitAndLossFigures;
use App\Services\Sheets\SheetBankOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Sheets tab — read-only, same figures as the web's
 * App\Livewire\Sheets\{SheetsIndex,SheetShow,SheetMonthly}. Exporting to
 * CSV/PDF and assigning an uncategorized expense's category both stay
 * web-only (file generation and an edit action, neither fits "read-only
 * figures") — see the "Open in Hive" button on the detail screen.
 */
class SheetController extends Controller
{
    /** The filter form's options: which banks a Sheet can be run against. */
    public function index(SheetBankOptions $options): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        return response()->json([
            'data' => $options->available()->map(fn ($bank) => [
                'id' => $bank->id,
                'name' => $bank->name,
                'accounts' => $bank->accounts->map(fn ($account) => [
                    'id' => $account->id,
                    'type' => $account->type,
                    'account_number' => $account->account_number,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        $bankAccountIds = array_values(array_filter(array_map(
            'intval',
            (array) $request->query('bank_account_ids', [])
        )));

        abort_if(empty($bankAccountIds), 422, 'Select at least one bank account.');

        $startDate = (string) $request->query('start_date', today()->subYear()->format('Y-m-d'));
        $endDate = (string) $request->query('end_date', today()->format('Y-m-d'));
        abort_unless(strtotime($startDate) !== false && strtotime($endDate) !== false, 422, 'Invalid date range.');
        abort_if($endDate < $startDate, 422, 'End date must be after the start date.');

        $cash = $request->query('cash', 'include') === 'hide' ? 'hide' : 'include';
        $basis = $request->query('basis', 'accrual') === 'cash' ? 'cash' : 'accrual';

        $figures = new ProfitAndLossFigures($startDate, $endDate, $bankAccountIds, $cash, $basis);

        $materialsTotal = $figures->costOfMaterialsSum();
        $laborTotal = $figures->costOfLaborSum();
        $cogsTotal = $materialsTotal + $laborTotal;
        $revenue = $figures->revenue();
        $grossProfit = $revenue - $cogsTotal;
        $uncategorizedSum = $figures->uncategorizedTransactionsSum();
        $generalExpenses = $figures->generalExpenses();
        $totalExpenses = $generalExpenses + $uncategorizedSum;
        $netOperatingIncome = $grossProfit - $totalExpenses;

        return response()->json([
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cash' => $cash,
                'basis' => $basis,
                'revenue' => round($revenue, 2),
                'cost_of_goods_sold' => [
                    'materials' => [
                        'total' => round($materialsTotal, 2),
                        'vendors' => $this->vendorGroupArray($figures->costOfMaterialsVendors()),
                    ],
                    'labor' => [
                        'total' => round($laborTotal, 2),
                        'vendors' => $this->vendorGroupArray($figures->costOfLaborVendors()),
                    ],
                    'total' => round($cogsTotal, 2),
                ],
                'gross_profit' => round($grossProfit, 2),
                'expenses' => [
                    'categories' => $this->categoriesArray($figures->sortedExpenseCategories()),
                    'uncategorized' => [
                        'total' => round($uncategorizedSum, 2),
                        'transactions' => $figures->transactionsNoAssociations()->map(fn ($transaction) => [
                            'id' => $transaction->id,
                            'name' => $transaction->plaid_merchant_name
                                ?: (is_array($transaction->details) ? ($transaction->details['name'] ?? 'Unknown') : 'Unknown'),
                            'date' => $transaction->transaction_date?->toDateString(),
                            'amount' => round((float) $transaction->amount, 2),
                        ])->values()->all(),
                    ],
                    'total' => round($totalExpenses, 2),
                ],
                'net_operating_income' => round($netOperatingIncome, 2),
                'net_income' => round($netOperatingIncome, 2),
                'web_path' => '/sheets/show?'.http_build_query([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'bank_account_ids' => $bankAccountIds,
                    'cash' => $cash,
                    'basis' => $basis,
                ]),
            ],
        ]);
    }

    public function monthly(MonthlyTrend $trend): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        return response()->json(['data' => $trend->trailingMonths()]);
    }

    /**
     * @return list<array{name: string, amount: float}>
     */
    private function vendorGroupArray($vendorGroups): array
    {
        return collect($vendorGroups)
            ->map(fn ($group, $name) => ['name' => (string) $name, 'amount' => round((float) collect($group)->sum('amount'), 2)])
            ->filter(fn (array $row) => $row['amount'] != 0.0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{subcategories: list<array>, sum: float, name: string}>  $sortedCategories
     * @return list<array<string, mixed>>
     */
    private function categoriesArray(array $sortedCategories): array
    {
        return collect($sortedCategories)
            ->filter(fn (array $category) => round((float) $category['sum'], 2) !== 0.0)
            ->map(fn (array $category) => [
                'name' => $category['name'],
                'amount' => round((float) $category['sum'], 2),
                'subcategories' => collect($category['subcategories'])
                    ->filter(fn (array $subcategory) => round((float) $subcategory['sum'], 2) !== 0.0)
                    ->map(fn (array $subcategory) => [
                        'name' => $subcategory['name'],
                        'amount' => round((float) $subcategory['sum'], 2),
                        'vendors' => collect($subcategory['vendors'])
                            ->filter(fn (array $vendor) => round((float) $vendor['sum'], 2) !== 0.0)
                            ->map(fn (array $vendor) => [
                                'name' => $vendor['name'],
                                'amount' => round((float) $vendor['sum'], 2),
                                'vendor_id' => $vendor['vendor_id'],
                            ])->values()->all(),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
