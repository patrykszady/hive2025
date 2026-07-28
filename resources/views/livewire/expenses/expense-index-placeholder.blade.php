{{-- Component-level skeleton (the card is lazy on projects.show / vendors.show).
     Uses the SHARED index-table skeleton with the same columnDefs and heading
     rule as the loaded card — it used to be a hand-rolled island-card with a
     hardcoded 4-row loop, which matched neither the chrome nor the row count. --}}
@php($tableView = $tableView ?? null)
<div wire:transition>
    <x-index-table.placeholder
        heading="Expenses"
        :columns="\App\Livewire\Expenses\ExpenseIndex::columnDefs($tableView)"
        :rows="$rows ?? \App\Livewire\Expenses\ExpenseIndex::placeholderRows($tableView)"
        {{-- Reserve the pagination footer when the first page is full, so the
             card doesn't grow ~53px the moment the real table arrives. --}}
        :page-size="\App\Livewire\Expenses\ExpenseIndex::placeholderRows($tableView)"
        :floor="$tableView === null"
    />
</div>
