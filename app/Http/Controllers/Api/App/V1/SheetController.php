<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\CategorizeSheetExpenseRequest;
use App\Http\Resources\Api\App\ExpenseResource;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Sheet;
use App\Services\Dashboard\MonthlyFinancialSummary;
use App\Services\Sheets\ProfitAndLossExporter;
use App\Services\Sheets\ProfitAndLossFigures;
use App\Services\Sheets\SheetBankOptions;
use App\Services\Sheets\UncategorizedExpenseAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;

/**
 * The app's Sheets tab — same figures as the web's App\Livewire\Sheets\
 * {SheetsIndex,SheetShow,SheetMonthly}, plus exporting to CSV/PDF
 * (exportCsvUrl()/exportPdfUrl()) and assigning an uncategorized expense's
 * category (categorizeExpense()).
 *
 * The export endpoints don't hand back a URL that re-runs the P&L query:
 * App\Scopes\{ExpenseScope,CheckScope,TransactionScope} only filter by
 * company when auth()->user() exists, and the signed download link
 * (SheetExportController, in routes/web.php) is deliberately session-less
 * like every other signed route in this app — re-running the report there
 * would mix every tenant's books together. Instead the file is rendered
 * once, right here, while still authenticated and correctly scoped (via
 * App\Services\Sheets\ProfitAndLossExporter, the same renderer
 * App\Livewire\Sheets\SheetShow::export_csv()/export_pdf() now call), then
 * stored under storage/app/exports/sheets and served back by a dumb,
 * scope-free file read — the same pattern EstimatePdfController::
 * signedContract() uses for an already-generated file.
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

        [$startDate, $endDate, $bankAccountIds, $cash, $basis] = $this->filtersFromRequest($request);
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
                // Mirrors SheetShow::uncategorizedExpenses() — expenses (not
                // bank transactions) in range with no category_id, offered
                // for inline categorization below.
                'uncategorized_expenses' => $figures->uncategorizedExpenses()->map(fn (Expense $expense) => [
                    'id' => $expense->id,
                    'amount' => (float) $expense->amount,
                    'date' => $expense->date?->toDateString(),
                    'vendor' => $expense->vendor?->business_name,
                ])->values()->all(),
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

    /** Assigns a category to an uncategorized expense — mirrors SheetShow::categorizeExpense(). */
    public function categorizeExpense(CategorizeSheetExpenseRequest $request, int $expense): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        $found = Expense::findOrFail($expense);
        $category = Category::findOrFail($request->validated('category_id'));

        app(UncategorizedExpenseAssigner::class)->assign($found, $category);

        return response()->json(['data' => (new ExpenseResource($found->refresh()))->toArray($request)]);
    }

    /** A signed link to an XLSX of the current filters — see this class's own docblock for why it's pre-rendered. */
    public function exportCsvUrl(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        [$startDate, $endDate, $bankAccountIds, $cash, $basis] = $this->filtersFromRequest($request);
        $figures = new ProfitAndLossFigures($startDate, $endDate, $bankAccountIds, $cash, $basis);
        $companyName = $request->user()->vendor->business_name ?? config('app.name');

        $export = app(ProfitAndLossExporter::class)->xlsx($figures, $startDate, $endDate, $basis, $companyName);

        return response()->json(['data' => $this->storeAndSign($export, 'sheets.export.csv.signed')]);
    }

    /** A signed link to a PDF of the current filters — see this class's own docblock for why it's pre-rendered. */
    public function exportPdfUrl(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        [$startDate, $endDate, $bankAccountIds, $cash, $basis] = $this->filtersFromRequest($request);
        $figures = new ProfitAndLossFigures($startDate, $endDate, $bankAccountIds, $cash, $basis);
        $companyName = $request->user()->vendor->business_name ?? config('app.name');

        $export = app(ProfitAndLossExporter::class)->pdf($figures, $startDate, $endDate, $basis, $companyName);

        return response()->json(['data' => $this->storeAndSign($export, 'sheets.export.pdf.signed')]);
    }

    /**
     * @param  array{binary: string, filename: string}  $export
     * @return array{url: string, expires_at: string}
     */
    private function storeAndSign(array $export, string $routeName): array
    {
        $extension = $routeName === 'sheets.export.csv.signed' ? 'xlsx' : 'pdf';
        $token = (string) Str::uuid();
        Storage::disk('local')->put("exports/sheets/{$token}.{$extension}", $export['binary']);

        $expiresAt = now()->addMinutes(10);

        return [
            'url' => URL::temporarySignedRoute($routeName, $expiresAt, ['token' => $token, 'filename' => $export['filename']]),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: list<int>, 3: string, 4: string} [startDate, endDate, bankAccountIds, cash, basis]
     */
    private function filtersFromRequest(Request $request): array
    {
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

        return [$startDate, $endDate, $bankAccountIds, $cash, $basis];
    }

    public function monthly(MonthlyFinancialSummary $monthlySummary): JsonResponse
    {
        $this->authorize('viewAny', Sheet::class);

        // No browser cookie to read "today" from here — same reasoning as
        // AppHomeAgenda::today() / HomeController's own call into this
        // service: Chicago is where the business runs.
        return response()->json(['data' => $monthlySummary->months(Carbon::now('America/Chicago')->startOfDay())]);
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
