<?php

namespace App\Livewire\Sheets;

use App\Services\Dashboard\MonthlyFinancialSummary;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class SheetMonthly extends Component
{
    public $months = [];

    public function mount()
    {
        $this->months = app(MonthlyFinancialSummary::class)->months(browser_today());
    }

    public function render()
    {
        return view('livewire.sheets.monthly');
    }
}
