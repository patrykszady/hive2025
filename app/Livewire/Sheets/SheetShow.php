<?php

namespace App\Livewire\Sheets;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sheet;
use App\Models\Vendor;
use App\Models\Timesheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use OpenSpout\Common\Entity\Row;

class SheetShow extends Component
{
    use AuthorizesRequests;

    public $start_date = null;
    public $end_date = null;
    public $bank_account_ids = [];
    public $cash = 'include';

    protected $queryString = [
        'start_date' => ['except' => ''],
        'end_date' => ['except' => ''],
        'bank_account_ids' => ['except' => ''],
        'cash' => ['except' => ''],
    ];

    #[Computed]
    public function revenue()
    {
        // Build base payment scope for date and valid projects
        $base = Payment::whereBetween('date', [$this->start_date, $this->end_date])
            ->whereHas('project', function ($query) {
                $query->whereHas('latestStatus', function ($query) {
                    $query->where('title', '!=', 'VIEW ONLY');
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
            })
            ->whereHas('transactions', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
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
            ->whereHas('transactions', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
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
            ->whereHas('transactions', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
            ->whereIn('vendor_id', $this->materialVendorIds())
            ->sum('amount');
    }

    #[Computed]
    public function generalExpenses()
    {
        return Expense::whereBetween('date', [$this->start_date, $this->end_date])
            ->whereHas('transactions', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
            ->whereNotIn('vendor_id', array_merge($this->materialVendorIds()->toArray(), $this->subVendorIds()->toArray()))
            ->whereNotIn('category_id', [123, 124, 125, 126, 127, 128])
            ->sum('amount');
    }

    #[Computed]
    public function generalExpenseCategories()
    {
        return Expense::whereBetween('date', [$this->start_date, $this->end_date])
            ->whereNotIn('category_id', [112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128])
            ->whereNotIn('vendor_id', array_merge($this->materialVendorIds()->toArray(), $this->subVendorIds()->toArray()))
            ->with(['category', 'vendor'])
            ->whereHas('transactions', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
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

    public function export_csv()
    {
        // Create filename
        $filename = 'financial-report-' . date('Y-m-d', strtotime($this->start_date)) . '-to-' . 
                    date('Y-m-d', strtotime($this->end_date)) . '.xlsx';
                    
        return response()->streamDownload(function() {
            // Format money values correctly
            $formatMoney = function($amount) {
                return money($amount);
            };
            
            // Treat very small amounts as zero if they round to $0.00
            $isZero = function($amount) {
                return round((float)$amount, 2) == 0.0;
            };

            // Styles
            $border = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
            );
            $headerStyle = (new Style())->setBorder($border)->setFontBold();
            $boldStyle = (new Style())->setFontBold();
            $italicStyle = (new Style())->setFontItalic();
            
            // OpenSpout writer
            $writer = new XLSXWriter();
            $writer->openToFile('php://output');
            
            // Name the sheet
            $writer->getCurrentSheet()->setName('Financial Report');
            
            // REVENUE
            $writer->addRow(Row::fromValues([
                'REVENUE', '', '', '', $formatMoney($this->revenue())
            ], $headerStyle));
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // COST OF REVENUE
            $writer->addRow(Row::fromValues([
                'COST OF REVENUE', '', '', '', $formatMoney($this->costOfMaterialsSum() + $this->costOfLaborSum())
            ], $headerStyle));
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // COST OF MATERIALS
            $writer->addRow(Row::fromValues([
                '', 'COST OF MATERIALS', '', '', $formatMoney($this->costOfMaterialsSum())
            ], $boldStyle));
            
            // Materials vendors (skip zero-sum vendors)
            foreach ($this->costOfMaterialsVendors() as $vendorName => $costOfMaterialsVendor) {
                $vendorSum = (float) $costOfMaterialsVendor->sum('amount');
                if ($isZero($vendorSum)) { continue; }
                $writer->addRow(Row::fromValues([
                    '', '', $vendorName, '', $formatMoney($vendorSum)
                ]));
            }
            
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // COST OF LABOR
            $writer->addRow(Row::fromValues([
                '', 'COST OF LABOR', '', '', $formatMoney($this->costOfLaborSum())
            ], $boldStyle));
            
            // Labor vendors (skip zero-sum vendors)
            foreach ($this->costOfLaborVendors() as $vendorName => $costOfLaborVendor) {
                $vendorSum = (float) $costOfLaborVendor->sum('amount');
                if ($isZero($vendorSum)) { continue; }
                $writer->addRow(Row::fromValues([
                    '', '', $vendorName, '', $formatMoney($vendorSum)
                ]));
            }
            
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // GROSS PROFIT
            $writer->addRow(Row::fromValues([
                'GROSS PROFIT', '', '', '', $formatMoney($this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum())
            ], $headerStyle));
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // GENERAL & ADMINISTRATIVE EXPENSES
            $writer->addRow(Row::fromValues([
                'GENERAL & ADMINISTRATIVE EXPENSES', '', '', '', $formatMoney($this->generalExpenses())
            ], $headerStyle));
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // Export categories following sort order (skip zero-sum groups)
            foreach ($this->sortedExpenseCategories() as $categoryName => $categoryData) {
                $categorySum = (float) $categoryData['sum'];
                if ($isZero($categorySum)) { continue; }

                $writer->addRow(Row::fromValues([
                    $categoryName, '', '', '', $formatMoney($categorySum)
                ], $boldStyle));
                
                foreach ($categoryData['subcategories'] as $subcategory) {
                    $subSum = (float) $subcategory['sum'];
                    if ($isZero($subSum)) { continue; }

                    $writer->addRow(Row::fromValuesWithStyles([
                        '', '', $subcategory['name'], '', $formatMoney($subSum)
                    ], null, [4 => $italicStyle]));
                    
                    foreach ($subcategory['vendors'] as $vendor) {
                        $vendSum = (float) $vendor['sum'];
                        if ($isZero($vendSum)) { continue; }

                        $writer->addRow(Row::fromValues([
                            '', '', '', $vendor['name'], $formatMoney($vendSum)
                        ]));
                    }
                    
                    // Add empty row after each subcategory
                    $writer->addRow(Row::fromValues(['', '', '', '', '']));
                }
                
                // Add empty row after each category
                $writer->addRow(Row::fromValues(['', '', '', '', '']));
            }
            
            $writer->addRow(Row::fromValues(['', '', '', '', ''])); // spacer
            
            // NET INCOME
            $writer->addRow(Row::fromValues([
                'NET INCOME', '', '', '', $formatMoney($this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum() - $this->generalExpenses())
            ], $headerStyle));
            
            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    #[Title('Sheet')]
    public function render()
    {
        $this->authorize('viewAny', Sheet::class);
        return view('livewire.sheets.show');
    }
}