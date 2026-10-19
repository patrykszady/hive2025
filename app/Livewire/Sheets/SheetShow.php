<?php

namespace App\Livewire\Sheets;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Sheet;
use App\Services\Sheets\ProfitAndLossFigures;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use Spatie\Browsershot\Browsershot;

class SheetShow extends Component
{
    use AuthorizesRequests;

    #[Url(except: '')]
    public $start_date = null;

    #[Url(except: '')]
    public $end_date = null;

    #[Url(except: [])]
    public $bank_account_ids = [];

    #[Url(except: '')]
    public $cash = 'include';

    #[Url(except: 'accrual')]
    public $basis = 'accrual';

    /**
     * The P&L figures for the current filter state — same constructor
     * params as App\Services\Sheets\ProfitAndLossFigures, memoized for the
     * life of this component instance (Livewire re-renders build a fresh
     * instance per request, so this never goes stale mid-request).
     */
    private ?ProfitAndLossFigures $figuresCache = null;

    private function figures(): ProfitAndLossFigures
    {
        return $this->figuresCache ??= new ProfitAndLossFigures(
            (string) $this->start_date,
            (string) $this->end_date,
            (array) $this->bank_account_ids,
            (string) $this->cash,
            (string) $this->basis,
        );
    }

    #[Computed]
    public function transactions_no_associations()
    {
        return $this->figures()->transactionsNoAssociations();
    }

    #[Computed]
    public function revenue()
    {
        return $this->figures()->revenue();
    }

    #[Computed]
    public function materialVendorIds()
    {
        return $this->figures()->materialVendorIds();
    }

    #[Computed]
    public function subVendorIds()
    {
        return $this->figures()->subVendorIds();
    }

    #[Computed]
    public function costOfLaborVendors()
    {
        return $this->figures()->costOfLaborVendors();
    }

    #[Computed]
    public function costOfLaborSum()
    {
        return $this->figures()->costOfLaborSum();
    }

    #[Computed]
    public function costOfMaterialsVendors()
    {
        return $this->figures()->costOfMaterialsVendors();
    }

    #[Computed]
    public function costOfMaterialsSum()
    {
        return $this->figures()->costOfMaterialsSum();
    }

    #[Computed]
    public function generalExpenses()
    {
        return $this->figures()->generalExpenses();
    }

    #[Computed]
    public function generalExpenseCategories()
    {
        return $this->figures()->generalExpenseCategories();
    }

    #[Computed]
    public function sortedExpenseCategories()
    {
        return $this->figures()->sortedExpenseCategories();
    }

    #[Computed]
    public function uncategorizedExpenses()
    {
        return $this->figures()->uncategorizedExpenses();
    }

    #[Computed]
    public function uncategorizedTransactionsSum(): float
    {
        return $this->figures()->uncategorizedTransactionsSum();
    }

    #[Computed]
    public function availableCategories()
    {
        return $this->figures()->availableCategories();
    }

    public function categorizeExpense(int $expenseId, ?string $categoryId): void
    {
        if (!$categoryId) {
            return;
        }

        $expense = Expense::findOrFail($expenseId);
        $category = Category::findOrFail((int) $categoryId);

        $expense->update(['category_id' => $category->id]);

        // Also set the vendor's default category if it doesn't have one
        if ($expense->vendor && !$expense->vendor->category_id) {
            $expense->vendor->update(['category_id' => $category->id]);
        }

        unset($this->uncategorizedExpenses);

        Flux::toast(
            variant: 'success',
            heading: 'Category Assigned',
            text: "Expense categorized as {$category->friendly_primary} — {$category->friendly_detailed}.",
        );
    }

    public function export_csv()
    {
        $filename = 'GS Construction and Remodeling - profit-and-loss-' . date('Y-m-d', strtotime($this->start_date)) . '-to-' . 
                    date('Y-m-d', strtotime($this->end_date)) . '.xlsx';
                    
        return response()->streamDownload(function() {
            $fmt = function($amount) {
                return round((float) $amount, 2);
            };

            $isZero = function($amount) {
                return round((float)$amount, 2) == 0.0;
            };

            // Indent helper — QuickBooks uses spaces for hierarchy depth
            $indent = function(string $label, int $depth = 0): string {
                return str_repeat('    ', $depth) . $label;
            };

            // Styles
            $border = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID)
            );
            $doubleBorder = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_DOUBLE)
            );
            $topBorder = new Border(
                new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID)
            );
            $boldStyle = (new Style())->setFontBold();
            $subheaderStyle = (new Style())->setFontBold()->setFontItalic();
            $normalStyle = new Style();
            $totalStyle = (new Style())->setFontBold()->setBorder($border);
            $categoryTotalStyle = (new Style())->setFontBold()->setBorder($topBorder);
            $grandTotalStyle = (new Style())->setFontBold()->setBorder($doubleBorder);
            $emptyRow = Row::fromValues(['']);
            
            $writer = new XLSXWriter();
            $writer->openToFile('php://output');
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Profit and Loss');
            $sheet->setColumnWidth(50, 1);  // Column A — labels
            $sheet->setColumnWidth(18, 2);  // Column B — amounts

            // QuickBooks header block
            $companyName = auth()->user()->vendor->business_name ?? config('app.name');
            $headerStyle = (new Style())->setFontBold()->setFontSize(11);
            $writer->addRow(Row::fromValues([$companyName], $headerStyle));
            $writer->addRow(Row::fromValues(['Profit and Loss'], $boldStyle));
            $writer->addRow(Row::fromValues([
                date('F j', strtotime($this->start_date)) . ' - ' . date('F j, Y', strtotime($this->end_date))
            ]));
            $basisLabel = $this->basis === 'cash' ? 'Cash Basis' : 'Accrual Basis';
            $writer->addRow(Row::fromValues([$basisLabel]));
            $writer->addRow(Row::fromValues([''])); // spacer

            // Column headers with underline
            $colHeaderLeftStyle = (new Style())->setFontBold()->setBorder($border);
            $colHeaderRightStyle = (new Style())->setFontBold()->setBorder($border)->setCellAlignment(CellAlignment::RIGHT);
            $writer->addRow(new Row([
                Cell::fromValue('', $colHeaderLeftStyle),
                Cell::fromValue('Total', $colHeaderRightStyle),
            ]));

            // ── INCOME ──
            $writer->addRow(Row::fromValues(['Income'], $boldStyle));
            $writer->addRow(Row::fromValues([$indent('Revenue', 1), $fmt($this->revenue)]));
            $writer->addRow(Row::fromValues([$indent('Total Income', 0), $fmt($this->revenue)], $totalStyle));
            $writer->addRow(Row::fromValues([''])); // spacer

            // ── COST OF GOODS SOLD ──
            $writer->addRow(Row::fromValues(['Cost of Goods Sold'], $boldStyle));

            // Materials
            $writer->addRow(Row::fromValues([$indent('Cost of Materials', 1)], $subheaderStyle));
            foreach ($this->costOfMaterialsVendors() as $vendorName => $costOfMaterialsVendor) {
                $vendorSum = (float) $costOfMaterialsVendor->sum('amount');
                if ($isZero($vendorSum)) { continue; }
                $writer->addRow(Row::fromValues([$indent($vendorName, 2), $fmt($vendorSum)]));
            }
            $writer->addRow(Row::fromValues([$indent('Total Cost of Materials', 1), $fmt($this->costOfMaterialsSum)], $categoryTotalStyle));
            $writer->addRow($emptyRow);

            // Labor
            $writer->addRow(Row::fromValues([$indent('Cost of Labor', 1)], $subheaderStyle));
            foreach ($this->costOfLaborVendors() as $vendorName => $costOfLaborVendor) {
                $vendorSum = (float) $costOfLaborVendor->sum('amount');
                if ($isZero($vendorSum)) { continue; }
                $writer->addRow(Row::fromValues([$indent($vendorName, 2), $fmt($vendorSum)]));
            }
            $writer->addRow(Row::fromValues([$indent('Total Cost of Labor', 1), $fmt($this->costOfLaborSum)], $categoryTotalStyle));
            $writer->addRow($emptyRow);

            $cogsTotal = $this->costOfMaterialsSum + $this->costOfLaborSum;
            $writer->addRow(Row::fromValues([$indent('Total Cost of Goods Sold', 0), $fmt($cogsTotal)], $grandTotalStyle));
            $writer->addRow($emptyRow); // spacer

            // ── GROSS PROFIT ──
            $grossProfit = $this->revenue - $cogsTotal;
            $writer->addRow(Row::fromValues(['Gross Profit', $fmt($grossProfit)], $grandTotalStyle));
            $writer->addRow(Row::fromValues([''])); // spacer

            // ── EXPENSES ──
            $writer->addRow(Row::fromValues(['Expenses'], $boldStyle));

            foreach ($this->sortedExpenseCategories() as $categoryName => $categoryData) {
                $categorySum = (float) $categoryData['sum'];
                if ($isZero($categorySum)) { continue; }

                $writer->addRow(Row::fromValues([$indent($categoryName, 1)], $boldStyle));

                foreach ($categoryData['subcategories'] as $subcategory) {
                    $subSum = (float) $subcategory['sum'];
                    if ($isZero($subSum)) { continue; }

                    // If only one vendor in subcategory, just show on one line
                    $nonZeroVendors = array_filter($subcategory['vendors'], fn($v) => !$isZero($v['sum']));
                    if (count($nonZeroVendors) === 1) {
                        $writer->addRow(Row::fromValues([$indent($subcategory['name'], 2), $fmt($subSum)]));
                    } else {
                        $writer->addRow(Row::fromValues([$indent($subcategory['name'], 2)], $subheaderStyle));

                        foreach ($subcategory['vendors'] as $vendor) {
                            $vendSum = (float) $vendor['sum'];
                            if ($isZero($vendSum)) { continue; }
                            $writer->addRow(Row::fromValues([$indent($vendor['name'], 3), $fmt($vendSum)]));
                        }

                        $writer->addRow(Row::fromValues([$indent('Total ' . $subcategory['name'], 2), $fmt($subSum)], $totalStyle));
                        $writer->addRow($emptyRow);
                    }
                }

                $writer->addRow(Row::fromValues([$indent('Total ' . $categoryName, 1), $fmt($categorySum)], $categoryTotalStyle));
                $writer->addRow($emptyRow);
            }

            // Uncategorized transactions (no expense/check association)
            $uncatSum = $this->uncategorizedTransactionsSum();
            if (!$isZero($uncatSum)) {
                $writer->addRow(Row::fromValues([$indent('Uncategorized', 1)], $boldStyle));
                foreach ($this->transactions_no_associations as $txn) {
                    $txnAmount = (float) $txn->amount;
                    if ($isZero($txnAmount)) { continue; }
                    $txnName = $txn->plaid_merchant_name
                        ?: (is_array($txn->details) ? ($txn->details['name'] ?? 'Unknown') : ($txn->details ?: 'Unknown'));
                    $writer->addRow(Row::fromValues([
                        $indent($txnName . ' (' . date('m/d/Y', strtotime($txn->transaction_date)) . ')', 2),
                        $fmt($txnAmount),
                    ]));
                }
                $writer->addRow(Row::fromValues([$indent('Total Uncategorized', 1), $fmt($uncatSum)], $categoryTotalStyle));
                $writer->addRow($emptyRow);
            } else {
                $uncatSum = 0.0;
            }

            $totalExpenses = $this->generalExpenses() + $uncatSum;
            $writer->addRow(Row::fromValues([$indent('Total Expenses', 0), $fmt($totalExpenses)], $grandTotalStyle));
            $writer->addRow($emptyRow); // spacer

            // ── NET OPERATING INCOME ──
            $netOperatingIncome = $grossProfit - $totalExpenses;
            $writer->addRow(Row::fromValues(['Net Operating Income', $fmt($netOperatingIncome)], $grandTotalStyle));
            $writer->addRow(Row::fromValues([''])); // spacer

            // ── NET INCOME ──
            $writer->addRow(Row::fromValues(['Net Income', $fmt($netOperatingIncome)], $grandTotalStyle));
            
            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function export_pdf()
    {
        $companyName = auth()->user()->vendor->business_name ?? config('app.name');
        $startFormatted = date('F j', strtotime($this->start_date));
        $endFormatted = date('F j, Y', strtotime($this->end_date));
        $dateRange = $startFormatted . ' - ' . $endFormatted;

        $cogsTotal = $this->costOfMaterialsSum + $this->costOfLaborSum;
        $grossProfit = $this->revenue - $cogsTotal;
        $uncatSum = $this->uncategorizedTransactionsSum();
        $totalExpenses = $this->generalExpenses() + $uncatSum;
        $netOperatingIncome = $grossProfit - $totalExpenses;

        $html = view('pdf.profit-and-loss', [
            'companyName' => $companyName,
            'dateRange' => $dateRange,
            'basisLabel' => $this->basis === 'cash' ? 'Cash' : 'Accrual',
            'revenue' => $this->revenue,
            'materialsVendors' => $this->costOfMaterialsVendors(),
            'materialsTotal' => $this->costOfMaterialsSum,
            'laborVendors' => $this->costOfLaborVendors(),
            'laborTotal' => $this->costOfLaborSum,
            'cogsTotal' => $cogsTotal,
            'grossProfit' => $grossProfit,
            'expenseCategories' => $this->sortedExpenseCategories(),
            'uncategorizedTransactions' => $this->transactions_no_associations,
            'uncategorizedSum' => $uncatSum,
            'expensesTotal' => $totalExpenses,
            'netOperatingIncome' => $netOperatingIncome,
            'netIncome' => $netOperatingIncome,
        ])->render();

        $browsershot = Browsershot::html($html)
            ->newHeadless()
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'single-process',
            ])
            ->timeout(60)
            ->showBackground()
            ->format('Letter')
            ->margins(10, 10, 10, 10);

        if ($chromePath = env('CHROME_PATH')) {
            $browsershot->setChromePath($chromePath);
        }

        $binary = $browsershot->pdf();

        $filename = 'GS Construction and Remodeling - profit-and-loss-' . date('Y-m-d', strtotime($this->start_date)) . '-to-' .
                    date('Y-m-d', strtotime($this->end_date)) . '.pdf';

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    #[Title('Sheet')]
    public function render()
    {
        $this->authorize('viewAny', Sheet::class);
        return view('livewire.sheets.show');
    }
}