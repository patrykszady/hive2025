<?php

use App\Jobs\BackfillReceiptHandwrittenNoteJob;
use App\Models\ExpenseReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('dispatches one job per matching receipt', function () {
    Bus::fake();

    ExpenseReceipts::query()->create([
        'expense_id' => 1,
        'receipt_filename' => 'r1.pdf',
        'receipt_items' => [],
        'created_at' => now()->subDays(1),
        'updated_at' => now()->subDays(1),
    ]);

    ExpenseReceipts::query()->create([
        'expense_id' => 2,
        'receipt_filename' => 'r2.pdf',
        'receipt_items' => ['handwritten_notes' => ['already']],
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    ExpenseReceipts::query()->create([
        'expense_id' => 3,
        'receipt_filename' => null,
        'receipt_items' => [],
        'created_at' => now()->subDays(1),
        'updated_at' => now()->subDays(1),
    ]);

    $this->artisan('receipts:dispatch-handwritten-notes-backfill --days=60')
        ->assertExitCode(0);

    Bus::assertDispatched(BackfillReceiptHandwrittenNoteJob::class, 2);
});

it('supports only-new filter before dispatching', function () {
    Bus::fake();

    ExpenseReceipts::query()->create([
        'expense_id' => 11,
        'receipt_filename' => 'new.pdf',
        'receipt_items' => [],
        'created_at' => now()->subDays(1),
        'updated_at' => now()->subDays(1),
    ]);

    ExpenseReceipts::query()->create([
        'expense_id' => 12,
        'receipt_filename' => 'existing.pdf',
        'receipt_items' => ['handwritten_notes' => ['911 Willow']],
        'created_at' => now()->subDays(1),
        'updated_at' => now()->subDays(1),
    ]);

    $this->artisan('receipts:dispatch-handwritten-notes-backfill --days=60 --only-new')
        ->assertExitCode(0);

    Bus::assertDispatched(BackfillReceiptHandwrittenNoteJob::class, 1);

    Bus::assertDispatched(BackfillReceiptHandwrittenNoteJob::class, function (BackfillReceiptHandwrittenNoteJob $job) {
        return $job->onlyNew === true;
    });
});
