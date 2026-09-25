<?php

use App\Livewire\Expenses\ExpenseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * buildFilterConditions() is private and builds a raw Meilisearch filter
 * string from client-writable #[Url] properties. These go straight at that
 * builder via reflection rather than a full page render, since exercising it
 * through Livewire would need a live Meilisearch server.
 */
function sec4_expenseIndexFilters(ExpenseIndex $component): array
{
    $method = new ReflectionMethod(ExpenseIndex::class, 'buildFilterConditions');
    $method->setAccessible(true);

    return $method->invoke($component);
}

it('drops an unlisted expense status instead of injecting it into the filter', function () {
    $component = new ExpenseIndex();
    $component->expense_statuses = ["Complete' OR belongs_to_vendor_id != 0 OR expense_status = 'Complete"];

    $conditions = sec4_expenseIndexFilters($component);

    expect(implode(' ', $conditions))->not->toContain('belongs_to_vendor_id');
});

it('keeps whitelisted statuses working', function () {
    $component = new ExpenseIndex();
    $component->expense_statuses = ['Complete', 'Missing Info'];

    $conditions = sec4_expenseIndexFilters($component);
    $joined = implode(' ', $conditions);

    expect($joined)->toContain("expense_status = 'Complete'")
        ->and($joined)->toContain("expense_status = 'Missing Info'");
});

it('casts a tampered distribution id to an integer instead of injecting it', function () {
    $component = new ExpenseIndex();
    $component->project_id = "D:1 OR belongs_to_vendor_id != 0";

    $conditions = sec4_expenseIndexFilters($component);

    expect(implode(' ', $conditions))->not->toContain('belongs_to_vendor_id');
});

it('still filters by a plain numeric distribution id', function () {
    $component = new ExpenseIndex();
    $component->project_id = 'D:42';

    $conditions = sec4_expenseIndexFilters($component);

    expect($conditions)->toContain('distribution_id = 42');
});

it('casts a numeric project_id to an integer in the filter', function () {
    $component = new ExpenseIndex();
    $component->project_id = '7';

    $conditions = sec4_expenseIndexFilters($component);

    expect($conditions)->toContain('(project_id = 7 OR split_project_ids = 7)');
});
