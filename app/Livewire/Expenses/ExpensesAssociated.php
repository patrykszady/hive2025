<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Scopes\VendorScope;
use Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ExpensesAssociated extends Component
{
    /** Most expenses a search shows. */
    public const SEARCH_LIMIT = 30;

    /** Most suggestions shown (newest first); a vendor like Home Depot has thousands of expenses. */
    public const SUGGESTION_LIMIT = 40;

    public Expense $expense;

    public $associate_expense = '';

    /** Suggested matches: the same vendor (newest first), or the same amount within three months. */
    public $expenses = [];

    /**
     * Anything else: an id, an amount, a vendor or an invoice. The suggestions
     * alone could not link expense 28701 ($1,500, GS Construction, 10/07) to
     * 28703 (-$44.99, Sixt, 10/06) — neither rule fits, so neither ever showed
     * in the other's list (Patryk, 2026-10-11).
     */
    public string $search = '';

    protected $listeners = ['addAssociatedExpense'];

    public function rules()
    {
        return [
            'associate_expense' => 'required',
        ];
    }

    public function addAssociatedExpense(Expense $expense)
    {
        $this->expense = $expense;
        $this->search = '';
        $this->associate_expense = '';

        $excludedIds = $expense->associated()->pluck('id')->toArray();
        $excludedIds[] = $expense->id;

        $this->expenses = Expense::query()
            // No eager loading of vendor here since we compare the ID directly.
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) use ($expense) {
                $query->where(function ($q) use ($expense) {
                        // Condition A: amount matches and date is within ±3 months.
                        $q->where(function ($amountQuery) use ($expense) {
                                $amountQuery->where('amount', $expense->amount)
                                            ->orWhere('amount', -1 * $expense->amount);
                            })
                          ->whereBetween('date', [
                              $expense->date->copy()->subMonths(3),
                              $expense->date->copy()->addMonths(3),
                          ]);
                    })
                    ->orWhere(function ($q) use ($expense) {
                        // Condition B: the same vendor, whenever — "the vendor
                        // should always match" (Patryk, 2026-10-11); this used
                        // to need the same calendar day as well.
                        $q->where('vendor_id', $expense->vendor_id);
                    });
            })
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::SUGGESTION_LIMIT)
            ->get();

        $this->modal('associated_expenses_form_modal')->show();
    }

    /**
     * What the picker lists: the suggestions, or — once something is typed —
     * every expense matching it, newest first. A number matches an id or an
     * amount (either sign); text matches the vendor's name or the invoice.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $term = trim($this->search);

        if ($term === '') {
            return collect($this->expenses);
        }

        $excludedIds = $this->expense->associated()->pluck('id')->all();
        $excludedIds[] = $this->expense->id;

        $number = is_numeric(str_replace([',', '$'], '', $term)) ? (float) str_replace([',', '$'], '', $term) : null;

        return Expense::query()
            ->with('vendor')
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) use ($term, $number) {
                if ($number !== null) {
                    $query->where('id', (int) $number)
                        ->orWhere('amount', $number)
                        ->orWhere('amount', -1 * $number);
                }

                $query->orWhereHas('vendor', fn ($vendor) => $vendor->withoutGlobalScope(VendorScope::class)->where('business_name', 'like', "%{$term}%"))
                    ->orWhere('invoice', 'like', "%{$term}%");
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();
    }

    public function save()
    {
        $associate = Expense::findOrFail($this->associate_expense);
        $current = $this->expense->fresh();

        $associateRootId = $associate->parent_expense_id ?: $associate->id;
        $currentRootId = $current->parent_expense_id ?: $current->id;

        if ($associateRootId !== $currentRootId) {
            $currentGroupIds = Expense::query()
                ->where(function ($query) use ($currentRootId) {
                    $query->where('id', $currentRootId)
                        ->orWhere('parent_expense_id', $currentRootId);
                })
                ->pluck('id');

            if ($currentGroupIds->isNotEmpty()) {
                Expense::query()
                    ->whereIn('id', $currentGroupIds)
                    ->where('id', '!=', $associateRootId)
                    ->update(['parent_expense_id' => $associateRootId]);
            }
        } elseif ($current->id !== $associateRootId) {
            $current->parent_expense_id = $associateRootId;
            $current->save();
        }

        $this->dispatch('refreshComponent')->to('expenses.expense-show');
        $this->modal('associated_expenses_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Expenses Associated',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.expenses.associated');
    }
}
