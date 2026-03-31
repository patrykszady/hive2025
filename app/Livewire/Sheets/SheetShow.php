<?php

namespace App\Livewire\Sheets;

use App\Models\Category;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sheet;
use App\Models\Vendor;
use App\Models\Transaction;
use App\Models\Timesheet;
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
     * Apply cash-basis filtering to an Expense query builder.
     * Only includes expenses that have been paid through bank (direct transaction or check→transaction).
     */
    private ?array $cashBasisExpenseIds = null;

    private function getCashBasisExpenseIds(): array
    {
        if ($this->cashBasisExpenseIds === null) {
            // Get expense IDs that have direct transactions in selected bank accounts
            $directIds = \DB::table('transactions')
                ->whereIn('bank_account_id', $this->bank_account_ids)
                ->whereNotNull('expense_id')
                ->pluck('expense_id')
                ->toArray();

            // Get expense IDs linked via checks that have transactions in selected bank accounts
            $checkIds = \DB::table('transactions')
                ->whereIn('bank_account_id', $this->bank_account_ids)
                ->whereNotNull('check_id')
                ->pluck('check_id')
                ->toArray();

            $checkExpenseIds = $checkIds
                ? \DB::table('expenses')
                    ->whereIn('check_id', $checkIds)
                    ->pluck('id')
                    ->toArray()
                : [];

            $this->cashBasisExpenseIds = array_unique(array_merge($directIds, $checkExpenseIds));
        }

        return $this->cashBasisExpenseIds;
    }

    private function applyCashBasisFilter($query)
    {
        if ($this->basis !== 'cash') {
            return $query;
        }

        return $query->whereIn('expenses.id', $this->getCashBasisExpenseIds());
    }

    #[Computed]
    public function transactions_no_associations()
    {
        if (!$this->end_date || empty($this->bank_account_ids)) {
            return collect();
        }

        $start = $this->start_date;
        $end = $this->end_date;

        return Transaction::whereBetween('transaction_date', [$start, $end])
            ->whereIn('bank_account_id', $this->bank_account_ids)
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->where(function ($q) {
                // Include non-deposits OR deposits without payments
                $q->whereNull('deposit')
                  ->orWhere('deposit', 0)
                  ->orWhere(function ($qq) {
                      $qq->where('deposit', 1)->doesntHave('payments');
                  });
            })
            ->orderBy('transaction_date')
            ->get();
    }

    #[Computed]
    public function revenue()
    {
        // Build base payment scope for date and valid projects
        $base = Payment::whereBetween('date', [$this->start_date, $this->end_date])
            ->whereHas('project', function ($query) {
                $query->whereHas('latestStatus', function ($query) {
                    $query->where('status_code', '!=', 11); // Exclude VIEW ONLY projects
                });
            });

        if ($this->cash === 'hide') {
            // Exclude cash payments and require transactions for non-cash in selected bank accounts
            return (clone $base)
                ->where('reference', '!=', 'Cash')
                ->whereHas('transaction', function ($query) {
                    $query->whereIn('bank_account_id', $this->bank_account_ids);
                })
                ->sum('amount');
        }

        // Include cash payments (no bank account filter) + non-cash payments with bank transactions
        $nonCash = (clone $base)
            ->where('reference', '!=', 'Cash')
            ->whereHas('transaction', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
            ->sum('amount');

        $cashSum = (clone $base)
            ->where('reference', '=', 'Cash')
            ->sum('amount');

        return $nonCash + $cashSum;
    }

    #[Computed]
    public function materialVendorIds()
    {
        return Vendor::where('sheets_type', 'Materials')->pluck('id');
    }

    #[Computed]
    public function subVendorIds()
    {
        return Vendor::whereNot('business_type', 'Retail')->pluck('id');
    }

    #[Computed(cache: false)]
    public function costOfLaborVendors()
    {
        // Get checks for non-retail subcontractors
        $checksQuery = Check::whereBetween('date', [$this->start_date, $this->end_date])
            ->whereHas('vendor', function ($query) {
                $query->where('business_type', '!=', 'Retail')
                      ->where('id', '!=', auth()->user()->vendor->id);
            });

        $checkVendors = $checksQuery
            ->get()
            ->groupBy('vendor.business_name')
            ->toBase();

        // Get timesheets for users who work via via_vendor_id relationship
        $viaVendorUsers = \DB::table('user_vendor')
            ->where('vendor_id', auth()->user()->vendor->id)
            ->whereNotNull('via_vendor_id')
            ->get(['user_id', 'via_vendor_id']);

        $timesheetVendors = collect();
        
        if ($viaVendorUsers->isNotEmpty()) {
            // Group by user_id first to avoid duplicates when users have multiple via_vendor relationships
            $userIds = $viaVendorUsers->pluck('user_id')->unique()->toArray();
            
            // Calculate net amounts per user using exact UserFinances logic
            foreach ($userIds as $userId) {
                $userVendorRel = $viaVendorUsers->where('user_id', $userId)->first();
                if ($userVendorRel) {
                    $viaVendor = \App\Models\Vendor::find($userVendorRel->via_vendor_id);
                    if ($viaVendor) {
                        // Use exact UserFinances "TOTAL CHECKS FOR USER" logic
                        $timesheetsPaid = Timesheet::where('user_id', $userId)
                            ->where('vendor_id', auth()->user()->vendor->id)
                            ->whereNull('paid_by')
                            ->whereHas('check', function ($query) {
                                $query->whereBetween('date', [$this->start_date, $this->end_date]);
                            })
                            ->sum('amount');
                        
                        $timesheetsPaidBy = Timesheet::withoutGlobalScopes()
                            ->where('user_id', $userId)
                            ->where('vendor_id', auth()->user()->vendor->id)
                            ->whereNotNull('paid_by')
                            ->whereHas('check', function ($query) {
                                $query->withoutGlobalScopes()->whereBetween('date', [$this->start_date, $this->end_date]);
                            })
                            ->sum('amount');
                        
                        $userReimbursementExpenses = Expense::whereNull('paid_by')
                            ->where('reimbursment', $userId)
                            ->whereHas('check', function ($query) {
                                $query->whereBetween('date', [$this->start_date, $this->end_date]);
                            })
                            ->sum('amount');
                        
                        $userReimbursementPaidBy = Expense::whereNotNull('paid_by')
                            ->where('reimbursment', $userId)
                            ->whereHas('check', function ($query) {
                                $query->whereBetween('date', [$this->start_date, $this->end_date]);
                            })
                            ->sum('amount');
                        
                        $user = \App\Models\User::find($userId);
                        $userDistribution = $user->distributions->first()->id ?? null;
                        $distributionChecks = $userDistribution
                            ? Expense::where('distribution_id', $userDistribution)
                                ->whereHas('check', function ($query) {
                                    $query->whereBetween('date', [$this->start_date, $this->end_date]);
                                })
                                ->sum('amount')
                            : 0;
                        
                        // Calculate "TOTAL CHECKS FOR USER" using exact UserFinances formula
                        $totalChecksForUser = $timesheetsPaid 
                            + $timesheetsPaidBy 
                            + $distributionChecks
                            - $userReimbursementExpenses 
                            - $userReimbursementPaidBy;
                        
                        // Check if user has expenses paid
                        $expensesPaid = Expense::where('paid_by', $userId)
                            ->whereHas('check', function ($query) {
                                $query->whereBetween('date', [$this->start_date, $this->end_date]);
                            })
                            ->sum('amount');
                        
                        // If user has expenses paid, use "TOTAL FOR USER" calculation
                        // Otherwise use "TOTAL CHECKS FOR USER" calculation
                        if ($expensesPaid > 0) {
                            // Use "TOTAL FOR USER" calculation (like Cezary)
                            $distributionExpenses = $userDistribution
                                ? Expense::where('distribution_id', $userDistribution)
                                    ->whereNull('check_id')
                                    ->whereBetween('date', [$this->start_date, $this->end_date])
                                    ->sum('amount')
                                : 0;
                            
                            $finalAmount = $timesheetsPaid
                                - $expensesPaid
                                + $distributionChecks
                                + $distributionExpenses
                                + $timesheetsPaidBy;
                        } else {
                            // Use "TOTAL CHECKS FOR USER" calculation (like Klaudiusz)
                            $finalAmount = $totalChecksForUser;
                        }
                        
                        if ($finalAmount > 0) {
                            $netEntry = (object) [
                                'amount' => $finalAmount,
                                'user_id' => $userId,
                                'vendor_id' => $viaVendor->id,
                            ];
                            
                            if (!$timesheetVendors->has($viaVendor->business_name)) {
                                $timesheetVendors->put($viaVendor->business_name, collect());
                            }
                            
                            $timesheetVendors->get($viaVendor->business_name)->push($netEntry);
                        }
                    }
                }
            }
        }

        // Merge check vendors and timesheet vendors
        $allVendors = $checkVendors;
        foreach ($timesheetVendors as $vendorName => $timesheets) {
            if ($allVendors->has($vendorName)) {
                // If vendor already exists from checks, add timesheet amounts
                $allVendors->get($vendorName)->push(...$timesheets);
            } else {
                // New vendor from timesheets only
                $allVendors->put($vendorName, $timesheets);
            }
        }
            
        // Sort vendors by sum
        return $allVendors->sortByDesc(function($vendor) {
            return $vendor->sum('amount');
        })->toBase();
    }

    #[Computed]
    public function costOfLaborSum()
    {
        return $this->costOfLaborVendors->flatten()->sum('amount');
    }

    #[Computed]
    public function costOfMaterialsVendors()
    {
        $vendors = Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereIn('vendor_id', $this->materialVendorIds())
                ->with(['vendor'])
                ->get()
            ->groupBy('vendor.business_name')
            ->toBase();
            
        // Sort vendors by sum
        return $vendors->sortByDesc(function($vendor) {
            return $vendor->sum('amount');
        })->toBase();
    }

    #[Computed]
    public function costOfMaterialsSum()
    {
        return Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereIn('vendor_id', $this->materialVendorIds())
                ->sum('amount');
    }

    #[Computed]
    public function generalExpenses()
    {
        return Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereNotIn('vendor_id', array_merge($this->materialVendorIds()->toArray(), $this->subVendorIds()->toArray()))
                ->whereNotIn('category_id', [112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128])
                ->sum('amount');
    }

    #[Computed]
    public function generalExpenseCategories()
    {
        return Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereNotIn('category_id', [112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128])
                ->whereNotIn('vendor_id', array_merge($this->materialVendorIds()->toArray(), $this->subVendorIds()->toArray()))
                ->with(['category', 'vendor'])
                ->get()
            ->groupBy('category.friendly_primary')
            ->toBase();
    }

    #[Computed]
    public function sortedExpenseCategories()
    {
        $sortedCategories = [];
        
        foreach ($this->generalExpenseCategories() as $categoryPrimaryName => $generalExpenseCategory) {
            $sortedDetailedCategories = [];
            
            foreach ($generalExpenseCategory->groupBy('category.friendly_detailed') as $categoryFriendlyDetailed => $categoryFriendlyExpenses) {
                $sortedVendors = [];
                
                foreach ($categoryFriendlyExpenses->groupBy('vendor.business_name') as $vendorName => $generalExpenseVendorExpenses) {
                    $sortedVendors[] = [
                        'name' => $vendorName ?: 'Unknown Vendor',
                        'sum' => $generalExpenseVendorExpenses->sum('amount'),
                        'vendor_id' => $generalExpenseVendorExpenses->first()->vendor_id ?? null,
                    ];
                }
                
                // Sort vendors by amount (descending)
                usort($sortedVendors, function($a, $b) {
                    return $b['sum'] <=> $a['sum'];
                });
                
                $sortedDetailedCategories[] = [
                    'name' => $categoryFriendlyDetailed ?: 'Uncategorized',
                    'sum' => $categoryFriendlyExpenses->sum('amount'),
                    'vendors' => $sortedVendors
                ];
            }
            
            // Sort detailed categories by amount (descending)
            usort($sortedDetailedCategories, function($a, $b) {
                return $b['sum'] <=> $a['sum'];
            });
            
            $sortedCategories[$categoryPrimaryName] = [
                'subcategories' => $sortedDetailedCategories,
                'sum' => $generalExpenseCategory->sum('amount'),
                'name' => $categoryPrimaryName
            ];
        }
        
        // Sort primary categories by sum (descending)
        uasort($sortedCategories, function($a, $b) {
            return $b['sum'] <=> $a['sum'];
        });
        
        return $sortedCategories;
    }

    #[Computed]
    public function uncategorizedExpenses()
    {
        if (!$this->end_date) {
            return collect();
        }

        return Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereNull('category_id')
                ->with(['vendor'])
                ->orderByDesc('amount')
                ->get();
    }

    #[Computed]
    public function uncategorizedTransactionsSum(): float
    {
        return round((float) $this->transactions_no_associations->sum('amount'), 2);
    }

    #[Computed]
    public function availableCategories()
    {
        return Category::orderBy('friendly_primary')
            ->orderBy('friendly_detailed')
            ->get();
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
            $writer->addRow(Row::fromValues([$indent('Revenue', 1), $fmt($this->revenue())]));
            $writer->addRow(Row::fromValues([$indent('Total Income', 0), $fmt($this->revenue())], $totalStyle));
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
            $writer->addRow(Row::fromValues([$indent('Total Cost of Materials', 1), $fmt($this->costOfMaterialsSum())], $categoryTotalStyle));
            $writer->addRow($emptyRow);

            // Labor
            $writer->addRow(Row::fromValues([$indent('Cost of Labor', 1)], $subheaderStyle));
            foreach ($this->costOfLaborVendors() as $vendorName => $costOfLaborVendor) {
                $vendorSum = (float) $costOfLaborVendor->sum('amount');
                if ($isZero($vendorSum)) { continue; }
                $writer->addRow(Row::fromValues([$indent($vendorName, 2), $fmt($vendorSum)]));
            }
            $writer->addRow(Row::fromValues([$indent('Total Cost of Labor', 1), $fmt($this->costOfLaborSum())], $categoryTotalStyle));
            $writer->addRow($emptyRow);

            $cogsTotal = $this->costOfMaterialsSum() + $this->costOfLaborSum();
            $writer->addRow(Row::fromValues([$indent('Total Cost of Goods Sold', 0), $fmt($cogsTotal)], $grandTotalStyle));
            $writer->addRow($emptyRow); // spacer

            // ── GROSS PROFIT ──
            $grossProfit = $this->revenue() - $cogsTotal;
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

            $writer->addRow(Row::fromValues([$indent('Total Expenses', 0), $fmt($this->generalExpenses())], $grandTotalStyle));
            $writer->addRow($emptyRow); // spacer

            // ── NET OPERATING INCOME ──
            $netOperatingIncome = $grossProfit - $this->generalExpenses();
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

        $cogsTotal = $this->costOfMaterialsSum() + $this->costOfLaborSum();
        $grossProfit = $this->revenue() - $cogsTotal;
        $netOperatingIncome = $grossProfit - $this->generalExpenses();

        $html = view('pdf.profit-and-loss', [
            'companyName' => $companyName,
            'dateRange' => $dateRange,
            'basisLabel' => $this->basis === 'cash' ? 'Cash' : 'Accrual',
            'revenue' => $this->revenue(),
            'materialsVendors' => $this->costOfMaterialsVendors(),
            'materialsTotal' => $this->costOfMaterialsSum(),
            'laborVendors' => $this->costOfLaborVendors(),
            'laborTotal' => $this->costOfLaborSum(),
            'cogsTotal' => $cogsTotal,
            'grossProfit' => $grossProfit,
            'expenseCategories' => $this->sortedExpenseCategories(),
            'expensesTotal' => $this->generalExpenses(),
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