<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;

uses(RefreshDatabase::class);

/**
 * expenses:rematch-suspect --apply clears the project of an expense whose
 * project does not fit its PO, for the matcher to run again. The matcher
 * finds unassigned expenses through the search index, and the clear was a
 * query update that never reached it, so nothing was ever re-matched
 * (2026-10-02, expense 28647: PO "oak park" on a Northbrook project).
 */
it('clears a suspect project and sends the expense back to the search index', function () {
    config(['scout.driver' => 'meilisearch', 'scout.queue' => true]);
    Queue::fake();

    $company = Vendor::factory()->create(['business_type' => 'Sub', 'registration' => ['registered' => true]]);
    $northbrook = Project::withoutEvents(fn () => Project::factory()->create(['address' => '3154 Violet Ln', 'project_name' => 'Home Renovation', 'belongs_to_vendor_id' => $company->id, 'client_id' => Client::factory()->create()->id]));
    $expense = Expense::withoutEvents(fn () => Expense::forceCreate([
        'amount' => 56.17,
        'date' => '2026-10-01',
        'vendor_id' => $company->id,
        'belongs_to_vendor_id' => $company->id,
        'project_id' => $northbrook->id,
        'created_by_user_id' => 0,
    ]));
    ExpenseReceipts::withoutEvents(fn () => ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'oak.pdf',
        'receipt_items' => ['purchase_order' => 'oak park'],
    ]));
    Queue::fake();

    $this->artisan('expenses:rematch-suspect', ['--expense' => [$expense->id], '--apply' => true])
        ->expectsConfirmation('Null project_id on 1 expense(s)?', 'yes')
        ->assertSuccessful();

    expect($expense->fresh()->project_id)->toBeNull();
    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job) => $job->models->contains('id', $expense->id));
});
