<?php

namespace App\Services\Sheets;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use Spatie\Browsershot\Browsershot;

/**
 * Rendering the P&L report as an XLSX (QuickBooks-style) or a PDF —
 * extracted from App\Livewire\Sheets\SheetShow::export_csv()/export_pdf()
 * so the Hive Contractors app's SheetController renders byte-for-byte the
 * same file. $companyName is passed in rather than read from auth() so this
 * also works from the app's authenticated request (the figures themselves
 * — $figures — were already built under that same request, so they are
 * scoped exactly like the web's).
 */
class ProfitAndLossExporter
{
    /** @return array{binary: string, filename: string} */
    public function xlsx(ProfitAndLossFigures $figures, string $startDate, string $endDate, string $basis, string $companyName): array
    {
        $fmt = fn ($amount) => round((float) $amount, 2);
        $isZero = fn ($amount) => round((float) $amount, 2) == 0.0;
        $indent = fn (string $label, int $depth = 0): string => str_repeat('    ', $depth).$label;

        $border = new Border(new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID));
        $doubleBorder = new Border(new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_DOUBLE));
        $topBorder = new Border(new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID));
        $boldStyle = (new Style())->setFontBold();
        $subheaderStyle = (new Style())->setFontBold()->setFontItalic();
        $totalStyle = (new Style())->setFontBold()->setBorder($border);
        $categoryTotalStyle = (new Style())->setFontBold()->setBorder($topBorder);
        $grandTotalStyle = (new Style())->setFontBold()->setBorder($doubleBorder);
        $emptyRow = Row::fromValues(['']);

        $tempPath = tempnam(sys_get_temp_dir(), 'pl-xlsx-');

        $writer = new XLSXWriter();
        $writer->openToFile($tempPath);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Profit and Loss');
        $sheet->setColumnWidth(50, 1);
        $sheet->setColumnWidth(18, 2);

        $headerStyle = (new Style())->setFontBold()->setFontSize(11);
        $writer->addRow(Row::fromValues([$companyName], $headerStyle));
        $writer->addRow(Row::fromValues(['Profit and Loss'], $boldStyle));
        $writer->addRow(Row::fromValues([
            date('F j', strtotime($startDate)).' - '.date('F j, Y', strtotime($endDate)),
        ]));
        $writer->addRow(Row::fromValues([$basis === 'cash' ? 'Cash Basis' : 'Accrual Basis']));
        $writer->addRow(Row::fromValues(['']));

        $colHeaderLeftStyle = (new Style())->setFontBold()->setBorder($border);
        $colHeaderRightStyle = (new Style())->setFontBold()->setBorder($border)->setCellAlignment(CellAlignment::RIGHT);
        $writer->addRow(new Row([
            Cell::fromValue('', $colHeaderLeftStyle),
            Cell::fromValue('Total', $colHeaderRightStyle),
        ]));

        // ── INCOME ──
        $writer->addRow(Row::fromValues(['Income'], $boldStyle));
        $writer->addRow(Row::fromValues([$indent('Revenue', 1), $fmt($figures->revenue())]));
        $writer->addRow(Row::fromValues([$indent('Total Income', 0), $fmt($figures->revenue())], $totalStyle));
        $writer->addRow($emptyRow);

        // ── COST OF GOODS SOLD ──
        $writer->addRow(Row::fromValues(['Cost of Goods Sold'], $boldStyle));

        $writer->addRow(Row::fromValues([$indent('Cost of Materials', 1)], $subheaderStyle));
        foreach ($figures->costOfMaterialsVendors() as $vendorName => $costOfMaterialsVendor) {
            $vendorSum = (float) $costOfMaterialsVendor->sum('amount');
            if ($isZero($vendorSum)) {
                continue;
            }
            $writer->addRow(Row::fromValues([$indent($vendorName, 2), $fmt($vendorSum)]));
        }
        $writer->addRow(Row::fromValues([$indent('Total Cost of Materials', 1), $fmt($figures->costOfMaterialsSum())], $categoryTotalStyle));
        $writer->addRow($emptyRow);

        $writer->addRow(Row::fromValues([$indent('Cost of Labor', 1)], $subheaderStyle));
        foreach ($figures->costOfLaborVendors() as $vendorName => $costOfLaborVendor) {
            $vendorSum = (float) $costOfLaborVendor->sum('amount');
            if ($isZero($vendorSum)) {
                continue;
            }
            $writer->addRow(Row::fromValues([$indent($vendorName, 2), $fmt($vendorSum)]));
        }
        $writer->addRow(Row::fromValues([$indent('Total Cost of Labor', 1), $fmt($figures->costOfLaborSum())], $categoryTotalStyle));
        $writer->addRow($emptyRow);

        $cogsTotal = $figures->costOfMaterialsSum() + $figures->costOfLaborSum();
        $writer->addRow(Row::fromValues([$indent('Total Cost of Goods Sold', 0), $fmt($cogsTotal)], $grandTotalStyle));
        $writer->addRow($emptyRow);

        // ── GROSS PROFIT ──
        $grossProfit = $figures->revenue() - $cogsTotal;
        $writer->addRow(Row::fromValues(['Gross Profit', $fmt($grossProfit)], $grandTotalStyle));
        $writer->addRow(Row::fromValues(['']));

        // ── EXPENSES ──
        $writer->addRow(Row::fromValues(['Expenses'], $boldStyle));

        foreach ($figures->sortedExpenseCategories() as $categoryName => $categoryData) {
            $categorySum = (float) $categoryData['sum'];
            if ($isZero($categorySum)) {
                continue;
            }

            $writer->addRow(Row::fromValues([$indent($categoryName, 1)], $boldStyle));

            foreach ($categoryData['subcategories'] as $subcategory) {
                $subSum = (float) $subcategory['sum'];
                if ($isZero($subSum)) {
                    continue;
                }

                $nonZeroVendors = array_filter($subcategory['vendors'], fn ($v) => ! $isZero($v['sum']));
                if (count($nonZeroVendors) === 1) {
                    $writer->addRow(Row::fromValues([$indent($subcategory['name'], 2), $fmt($subSum)]));
                } else {
                    $writer->addRow(Row::fromValues([$indent($subcategory['name'], 2)], $subheaderStyle));

                    foreach ($subcategory['vendors'] as $vendor) {
                        $vendSum = (float) $vendor['sum'];
                        if ($isZero($vendSum)) {
                            continue;
                        }
                        $writer->addRow(Row::fromValues([$indent($vendor['name'], 3), $fmt($vendSum)]));
                    }

                    $writer->addRow(Row::fromValues([$indent('Total '.$subcategory['name'], 2), $fmt($subSum)], $totalStyle));
                    $writer->addRow($emptyRow);
                }
            }

            $writer->addRow(Row::fromValues([$indent('Total '.$categoryName, 1), $fmt($categorySum)], $categoryTotalStyle));
            $writer->addRow($emptyRow);
        }

        $uncatSum = $figures->uncategorizedTransactionsSum();
        if (! $isZero($uncatSum)) {
            $writer->addRow(Row::fromValues([$indent('Uncategorized', 1)], $boldStyle));
            foreach ($figures->transactionsNoAssociations() as $txn) {
                $txnAmount = (float) $txn->amount;
                if ($isZero($txnAmount)) {
                    continue;
                }
                $txnName = $txn->plaid_merchant_name
                    ?: (is_array($txn->details) ? ($txn->details['name'] ?? 'Unknown') : ($txn->details ?: 'Unknown'));
                $writer->addRow(Row::fromValues([
                    $indent($txnName.' ('.date('m/d/Y', strtotime($txn->transaction_date)).')', 2),
                    $fmt($txnAmount),
                ]));
            }
            $writer->addRow(Row::fromValues([$indent('Total Uncategorized', 1), $fmt($uncatSum)], $categoryTotalStyle));
            $writer->addRow($emptyRow);
        } else {
            $uncatSum = 0.0;
        }

        $totalExpenses = $figures->generalExpenses() + $uncatSum;
        $writer->addRow(Row::fromValues([$indent('Total Expenses', 0), $fmt($totalExpenses)], $grandTotalStyle));
        $writer->addRow($emptyRow);

        $netOperatingIncome = $grossProfit - $totalExpenses;
        $writer->addRow(Row::fromValues(['Net Operating Income', $fmt($netOperatingIncome)], $grandTotalStyle));
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['Net Income', $fmt($netOperatingIncome)], $grandTotalStyle));

        $writer->close();

        $binary = file_get_contents($tempPath);
        unlink($tempPath);

        return ['binary' => (string) $binary, 'filename' => $this->filename('xlsx', $startDate, $endDate)];
    }

    /** @return array{binary: string, filename: string} */
    public function pdf(ProfitAndLossFigures $figures, string $startDate, string $endDate, string $basis, string $companyName): array
    {
        $startFormatted = date('F j', strtotime($startDate));
        $endFormatted = date('F j, Y', strtotime($endDate));

        $cogsTotal = $figures->costOfMaterialsSum() + $figures->costOfLaborSum();
        $grossProfit = $figures->revenue() - $cogsTotal;
        $uncatSum = $figures->uncategorizedTransactionsSum();
        $totalExpenses = $figures->generalExpenses() + $uncatSum;
        $netOperatingIncome = $grossProfit - $totalExpenses;

        $html = view('pdf.profit-and-loss', [
            'companyName' => $companyName,
            'dateRange' => $startFormatted.' - '.$endFormatted,
            'basisLabel' => $basis === 'cash' ? 'Cash' : 'Accrual',
            'revenue' => $figures->revenue(),
            'materialsVendors' => $figures->costOfMaterialsVendors(),
            'materialsTotal' => $figures->costOfMaterialsSum(),
            'laborVendors' => $figures->costOfLaborVendors(),
            'laborTotal' => $figures->costOfLaborSum(),
            'cogsTotal' => $cogsTotal,
            'grossProfit' => $grossProfit,
            'expenseCategories' => $figures->sortedExpenseCategories(),
            'uncategorizedTransactions' => $figures->transactionsNoAssociations(),
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

        return ['binary' => $browsershot->pdf(), 'filename' => $this->filename('pdf', $startDate, $endDate)];
    }

    /**
     * The web hardcodes this prefix regardless of the signed-in vendor's
     * actual name (unlike the in-sheet/PDF header, which uses $companyName)
     * — kept exactly as-is so the downloaded filename matches byte for byte.
     */
    private function filename(string $extension, string $startDate, string $endDate): string
    {
        return 'GS Construction and Remodeling - profit-and-loss-'.date('Y-m-d', strtotime($startDate)).'-to-'.date('Y-m-d', strtotime($endDate)).'.'.$extension;
    }
}
