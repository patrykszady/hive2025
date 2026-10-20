<?php

namespace App\Livewire\Sheets;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Sheet;
use App\Services\Sheets\ProfitAndLossExporter;
use App\Services\Sheets\ProfitAndLossFigures;
use App\Services\Sheets\UncategorizedExpenseAssigner;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

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

        app(UncategorizedExpenseAssigner::class)->assign($expense, $category);

        unset($this->uncategorizedExpenses);

        Flux::toast(
            variant: 'success',
            heading: 'Category Assigned',
            text: "Expense categorized as {$category->friendly_primary} — {$category->friendly_detailed}.",
        );
    }

    public function export_csv()
    {
        $companyName = auth()->user()->vendor->business_name ?? config('app.name');
        $export = app(ProfitAndLossExporter::class)->xlsx($this->figures(), (string) $this->start_date, (string) $this->end_date, (string) $this->basis, $companyName);

        return response()->streamDownload(function () use ($export) {
            echo $export['binary'];
        }, $export['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function export_pdf()
    {
        $companyName = auth()->user()->vendor->business_name ?? config('app.name');
        $export = app(ProfitAndLossExporter::class)->pdf($this->figures(), (string) $this->start_date, (string) $this->end_date, (string) $this->basis, $companyName);

        return response()->streamDownload(function () use ($export) {
            echo $export['binary'];
        }, $export['filename'], [
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