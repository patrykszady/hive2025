<?php

namespace App\Livewire\Sheets;

use App\Services\Sheets\MonthlyTrend;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class SheetMonthly extends Component
{
    public $months = [];

    public function mount(MonthlyTrend $trend)
    {
        $this->months = $trend->trailingMonths();
    }

    public function render()
    {
        return view('livewire.sheets.monthly');
    }
}
