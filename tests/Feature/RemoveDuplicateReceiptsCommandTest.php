<?php

use App\Models\ExpenseReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * receipts:remove-duplicates — the same file attached to one expense twice
 * (2026-10-02: two Menards batches imported side by side). The oldest stays,
 * copies are soft-deleted, other receipts are left alone.
 */
function dupReceipt(int $expenseId, ?string $file): ExpenseReceipts
{
    return ExpenseReceipts::query()->create(['expense_id' => $expenseId, 'receipt_filename' => $file, 'receipt_items' => []]);
}

it('lists the copies without touching them unless told to commit', function () {
    $first = dupReceipt(28647, '28647-menards-2026-10-01-56_17.pdf');
    $copy = dupReceipt(28647, '28647-menards-2026-10-01-56_17.pdf');

    $this->artisan('receipts:remove-duplicates')
        ->expectsOutputToContain("Would remove receipt {$copy->id} on expense 28647")
        ->assertSuccessful();

    expect(ExpenseReceipts::query()->whereKey([$first->id, $copy->id])->count())->toBe(2);
});

it('keeps the oldest of each pair and leaves different files and other expenses alone', function () {
    $first = dupReceipt(28647, 'a.pdf');
    $copy = dupReceipt(28647, 'a.pdf');
    $other = dupReceipt(28647, 'b.pdf');
    $elsewhere = dupReceipt(28648, 'a.pdf');
    $unnamed = [dupReceipt(28649, null), dupReceipt(28649, null)];

    $this->artisan('receipts:remove-duplicates', ['--commit' => true])->assertSuccessful();

    expect(ExpenseReceipts::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first->id, $other->id, $elsewhere->id, ...array_map(fn ($r) => $r->id, $unnamed)])->sort()->values()->all())
        ->and(ExpenseReceipts::withTrashed()->find($copy->id)->trashed())->toBeTrue();
});

it('limits itself to the expenses named', function () {
    dupReceipt(28647, 'a.pdf');
    $keptCopy = dupReceipt(28647, 'a.pdf');
    dupReceipt(9290, 'old.pdf');
    $oldCopy = dupReceipt(9290, 'old.pdf');

    $this->artisan('receipts:remove-duplicates', ['--commit' => true, '--expense' => [9290]])->assertSuccessful();

    expect(ExpenseReceipts::query()->find($keptCopy->id))->not->toBeNull()
        ->and(ExpenseReceipts::query()->find($oldCopy->id))->toBeNull();
});
