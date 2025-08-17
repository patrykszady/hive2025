<?php

namespace App\Livewire\Sheets;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sheet;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

class SheetShow extends Component
{
    use AuthorizesRequests;

    public $start_date = null;
    public $end_date = null;
    public $bank_account_ids = [];

    protected $queryString = [
        'start_date' => ['except' => ''],
        'end_date' => ['except' => ''],
        'bank_account_ids' => ['except' => ''],
    ];

    public function mount()
    {
        // Keep only initialization logic here
        // All calculations now moved to computed properties
    }

    #[Computed]
    public function revenue()
    {
        return Payment::whereBetween('date', [$this->start_date, $this->end_date])
            ->with(['transaction', 'project'])
            ->whereHas('project', function ($query) {
                $query->whereHas('latestStatus', function ($query) {
                    $query->where('title', '!=', 'VIEW ONLY');
                });
            })
            ->whereHas('transaction', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
            ->sum('amount');
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

    #[Computed]
    public function costOfLaborVendors()
    {
        $vendors = Check::whereBetween('date', [$this->start_date, $this->end_date])
            ->whereNot('check_type', 'Cash')
            ->whereHas('vendor', function ($query) {
                $query->where('business_type', '!=', 'Retail')
                      ->where('id', '!=', auth()->user()->vendor->id);
            })
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
    public function costOfLaborSum()
    {
        return $this->costOfLaborVendors()->flatten()->sum('amount');
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
        $border = new Border(
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
        );
        $border_thin = new Border(
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID)
        );

        // Create filename
        $filename = 'financial-report-' . date('Y-m-d', strtotime($this->start_date)) . '-to-' . 
                    date('Y-m-d', strtotime($this->end_date)) . '.xlsx';
                    
        return response()->streamDownload(function() use ($border, $border_thin) {
            // Format money values correctly without currency symbols
            $formatMoney = function($amount) {
                return number_format($amount, 2, '.', '');
            };
            
            // Create Excel file using PHPSpreadsheet since SimpleExcelWriter::createFromString() doesn't exist
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Add header row
            $sheet->setCellValue('A1', 'Category');
            $sheet->setCellValue('B1', 'Sub-Category');
            $sheet->setCellValue('C1', 'Vendor');
            $sheet->setCellValue('D1', 'Amount');
            
            $row = 2;
            
            // REVENUE
            $sheet->setCellValue('A' . $row, 'REVENUE');
            $sheet->setCellValue('D' . $row, $formatMoney($this->revenue()));
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            $row += 2;
            
            // COST OF REVENUE
            $sheet->setCellValue('A' . $row, 'COST OF REVENUE');
            $sheet->setCellValue('D' . $row, $formatMoney($this->costOfMaterialsSum() + $this->costOfLaborSum()));
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            $row += 2;
            
            // COST OF MATERIALS
            $sheet->setCellValue('B' . $row, 'COST OF MATERIALS');
            $sheet->setCellValue('D' . $row, $formatMoney($this->costOfMaterialsSum()));
            $sheet->getStyle('B' . $row . ':D' . $row)->getFont()->setBold(true);
            $row++;
            
            // Materials vendors
            foreach ($this->costOfMaterialsVendors() as $vendorName => $costOfMaterialsVendor) {
                $sheet->setCellValue('C' . $row, $vendorName);
                $sheet->setCellValue('D' . $row, $formatMoney($costOfMaterialsVendor->sum('amount')));
                $row++;
            }
            
            $row++;
            
            // COST OF LABOR
            $sheet->setCellValue('B' . $row, 'COST OF LABOR');
            $sheet->setCellValue('D' . $row, $formatMoney($this->costOfLaborSum()));
            $sheet->getStyle('B' . $row . ':D' . $row)->getFont()->setBold(true);
            $row++;
            
            // Labor vendors
            foreach ($this->costOfLaborVendors() as $vendorName => $costOfLaborVendor) {
                $sheet->setCellValue('C' . $row, $vendorName);
                $sheet->setCellValue('D' . $row, $formatMoney($costOfLaborVendor->sum('amount')));
                $row++;
            }
            
            $row++;
            
            // GROSS PROFIT
            $sheet->setCellValue('A' . $row, 'GROSS PROFIT');
            $sheet->setCellValue('D' . $row, $formatMoney($this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum()));
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            $row += 2;
            
            // GENERAL & ADMINISTRATIVE EXPENSES
            $sheet->setCellValue('A' . $row, 'GENERAL & ADMINISTRATIVE EXPENSES');
            $sheet->setCellValue('D' . $row, $formatMoney($this->generalExpenses()));
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            $row += 2;
            
            // Export categories following sort order
            foreach ($this->sortedExpenseCategories() as $categoryName => $categoryData) {
                $sheet->setCellValue('A' . $row, $categoryName);
                $sheet->setCellValue('D' . $row, $formatMoney($categoryData['sum']));
                $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
                $row++;
                
                foreach ($categoryData['subcategories'] as $subcategory) {
                    $sheet->setCellValue('B' . $row, $subcategory['name']);
                    $sheet->setCellValue('D' . $row, $formatMoney($subcategory['sum']));
                    $sheet->getStyle('B' . $row . ':D' . $row)->getFont()->setItalic(true);
                    $row++;
                    
                    foreach ($subcategory['vendors'] as $vendor) {
                        $sheet->setCellValue('C' . $row, $vendor['name']);
                        $sheet->setCellValue('D' . $row, $formatMoney($vendor['sum']));
                        $row++;
                    }
                }
            }
            
            $row++;
            
            // NET INCOME
            $sheet->setCellValue('A' . $row, 'NET INCOME');
            $sheet->setCellValue('D' . $row, $formatMoney($this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum() - $this->generalExpenses()));
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            
            // Format amount column as currency
            $sheet->getStyle('D2:D' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            
            // Auto-size columns
            foreach(range('A', 'D') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            
            // Output spreadsheet directly
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
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