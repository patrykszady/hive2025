<?php

namespace App\Livewire\Sheets;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sheet;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public $cost_of_labor_sum = 0;

    public $cost_of_materials_sum = 0;

    public $general_expenses = 0;

    public $revenue = 0;

    public $cost_of_materials_vendors = [];

    public $cost_of_labor_vendors = [];

    public $general_expense_categories = [];

    // protected $listeners = ['sheet_info'];

    protected $queryString = [
        'start_date' => ['except' => ''],
        'end_date' => ['except' => ''],
        'bank_account_ids' => ['except' => ''],
    ];

    public function mount()
    {
        //08/23/2024 move to middleware .. somehwere else in the onion... not here!
        // if($this->year == ''){
        //     return(redirect('sheets'));
        // }

        //employed between the dates?
        $vendor_admins = auth()->user()->vendor->users()->employed()->wherePivot('role_id', 1)->pluck('user_id')->toArray();

        //1-22-24 do not show CASH when preparing TAXES
        //(float)
        $this->revenue = Payment::whereBetween('date', [$this->start_date, $this->end_date])
            ->with(['transaction', 'project'])
            ->whereHas('project', function ($query) {
                $query->whereHas('last_status', function ($query) {
                    $query->where('title', '!=', 'VIEW ONLY');
                });
            })
            ->whereHas('transaction', function ($query) {
                $query->whereIn('bank_account_id', $this->bank_account_ids);
            })
            ->sum('amount');

        $cost_of_labor =
            Check::
                //where check cleared account, not when entered
                whereBetween('date', [$this->start_date, $this->end_date])
                    ->whereNot('check_type', 'Cash')
                // ->where(function($query) use($vendor_admins){
                //     $query->whereNotIn('user_id', $vendor_admins)->orWhere('user_id', NULL);
                // })
                    ->whereHas('vendor', function ($query) {
                        //->where('business_name', 'Jesus De La Torre')
                        $query->where('business_type', '!=', 'Retail')->where('id', '!=', auth()->user()->vendor->id);
                    })
                    ->whereHas('transactions', function ($query) {
                        $query->whereIn('bank_account_id', $this->bank_account_ids);
                    });
        // ->get()
        // ->groupBy('vendor.business_name');

        $this->cost_of_labor_vendors = $cost_of_labor->get()->groupBy('vendor.business_name')->toBase();
        $this->cost_of_labor_sum = $cost_of_labor->get()->sum('amount');

        //material or NOT GENERAL/ADMINISTRATIVE
        $material_vendor_ids = Vendor::where('sheets_type', 'Materials')->pluck('id');
        $sub_vendors_ids = Vendor::whereNot('business_type', 'Retail')->pluck('id');

        $this->general_expense_categories =
            Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereNotIn('category_id', [112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128])
                ->whereNotIn('vendor_id', array_merge($material_vendor_ids->toArray(), $sub_vendors_ids->toArray()))
                ->with(['category', 'vendor'])
                ->whereHas('transactions', function ($query) {
                    $query->whereIn('bank_account_id', $this->bank_account_ids);
                })
                ->get()
                // ->groupBy(['category.friendly_detailed', 'vendor.busienss_name'])
                ->groupBy('category.friendly_primary')
                ->toBase();

        $this->cost_of_materials_vendors =
            Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereIn('vendor_id', $material_vendor_ids)
                ->with(['vendor'])
                ->whereHas('transactions', function ($query) {
                    $query->whereIn('bank_account_id', $this->bank_account_ids);
                })
                ->get()
                ->groupBy('vendor.business_name')
                ->toBase();
        $this->cost_of_materials_sum =
            Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereHas('transactions', function ($query) {
                    $query->whereIn('bank_account_id', $this->bank_account_ids);
                })
                ->whereIn('vendor_id', $material_vendor_ids)
                ->sum('amount');
        $this->general_expenses =
            Expense::whereBetween('date', [$this->start_date, $this->end_date])
                ->whereHas('transactions', function ($query) {
                    $query->whereIn('bank_account_id', $this->bank_account_ids);
                })
                ->whereNotIn('vendor_id', array_merge($material_vendor_ids->toArray(), $sub_vendors_ids->toArray()))
                ->whereNotIn('category_id', [123, 124, 125, 126, 127, 128])
                ->sum('amount');
    }

    public function export_csv()
    {
        $border = new Border(
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
        );
        $border_thin = new Border(
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID)
        );

        $writer = SimpleExcelWriter::create('test-'.mt_rand(0, 19999999).'.xlsx')->addHeader([]);

        $writer->addRow([
            'category' => 'REVENUE',
            'sub_category' => null,
            'vendor' => null,
            'amount' => money($this->revenue),
        ], (new Style)->setFontBold()->setBorder($border));

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        $writer->addRow([
            'category' => 'COST OF REVENUE',
            'sub_category' => null,
            'vendor' => null,
            'amount' => money($this->cost_of_materials_sum + $this->cost_of_labor_sum),
        ], (new Style)->setFontBold()->setBorder($border));

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        $writer->addRow([
            'category' => null,
            'sub_category' => 'COST OF MATERIALS',
            'vendor' => null,
            'amount' => money($this->cost_of_materials_sum),
        ], (new Style)->setFontBold()->setBorder($border));

        foreach ($this->cost_of_materials_vendors as $vendor_name => $cost_of_materials_vendor) {
            $writer->addRow([
                'category' => null,
                'sub_category' => null,
                'vendor' => $vendor_name,
                'amount' => money($cost_of_materials_vendor->sum('amount')),
            ]);
        }

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        $writer->addRow([
            'category' => null,
            'sub_category' => 'COST OF LABOR',
            'vendor' => null,
            'amount' => money($this->cost_of_labor_sum),
        ], (new Style)->setFontBold()->setBorder($border));

        foreach ($this->cost_of_labor_vendors as $vendor_name => $cost_of_labor_vendor) {
            $writer->addRow([
                'category' => null,
                'sub_category' => null,
                'vendor' => $vendor_name,
                'amount' => money($cost_of_labor_vendor->sum('amount')),
            ]);
        }

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        $writer->addRow([
            'category' => 'GROSS PROFIT',
            'sub_category' => null,
            'vendor' => null,
            'amount' => money($this->revenue - $this->cost_of_labor_sum - $this->cost_of_materials_sum),
        ], (new Style)->setFontBold()->setBorder($border));

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        $writer->addRow([
            'category' => 'GENERAL & ADMINISTRATIVE EXPENSES',
            'sub_category' => null,
            'vendor' => null,
            'amount' => money($this->general_expenses),
        ], (new Style)->setFontBold()->setBorder($border));

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        foreach ($this->general_expense_categories as $category_primary_name => $general_expense_category) {
            $writer->addRow([
                'category' => $category_primary_name,
                'sub_category' => null,
                'vendor' => null,
                'amount' => money($general_expense_category->sum('amount')),
            ], (new Style)->setFontBold()->setBorder($border));

            foreach ($general_expense_category->groupBy('category.friendly_detailed') as $category_friendly_detailed => $category_friendly_expenses) {
                $writer->addRow([
                    'category' => null,
                    'sub_category' => $category_friendly_detailed,
                    'vendor' => null,
                    'amount' => money($category_friendly_expenses->sum('amount')),
                ], (new Style)->setFontItalic()->setBorder($border_thin));

                foreach ($category_friendly_expenses->groupBy('vendor.busienss_name') as $vendor_name => $general_expense_vendor_expenses) {
                    $writer->addRow([
                        'category' => null,
                        'sub_category' => null,
                        'vendor' => $vendor_name,
                        'amount' => money($general_expense_vendor_expenses->sum('amount')),
                    ]);
                }
            }
        }

        $writer->addRow([
            'category' => null,
            'sub_category' => null,
            'vendor' => null,
            'amount' => null,
        ]);

        $writer->addRow([
            'category' => 'NET INCOME',
            'sub_category' => null,
            'vendor' => null,
            'amount' => money($this->revenue - $this->cost_of_labor_sum - $this->cost_of_materials_sum - $this->general_expenses),
        ], (new Style)->setFontBold()->setBorder($border));
    }

    #[Title('Sheet')]
    public function render()
    {
        $this->authorize('viewAny', Sheet::class);

        return view('livewire.sheets.show');
    }
}
