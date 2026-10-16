<?php

use App\Livewire\VendorDocs\AuditShow;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// "Download bank statements" on an audit: one merged PDF of the statements
// posted in the period, oldest first (it used to be a zip of separate files),
// and the months Plaid has no statement for asked of the bank on the spot,
// each range once (Plaid bills per statement) — 2026-10-16. Plaid's
// /statements/list gives each statement month, year and date_posted, no
// start or end date (2026-10-15).
// =========================================================================

/** @return array{account: BankAccount, bank: Bank} */
function statementsAudit(): array
{
    $gc = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Audit', 'last_name' => 'Admin', 'email' => 'statements-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $gc->id,
    ]);
    $user->vendors()->attach($gc->id, ['role_id' => 1]);
    test()->actingAs($user);

    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $gc->id, 'plaid_ins_id' => 'ins_5', 'plaid_item_id' => 'item-test', 'plaid_access_token' => 'access-test-not-a-real-token']);
    $account = BankAccount::create(['vendor_id' => $gc->id, 'bank_id' => $bank->id, 'account_number' => '1828', 'plaid_account_id' => 'plaid-acct-1', 'type' => 'checking']);

    return ['account' => $account, 'bank' => $bank];
}

/** A real one-page PDF reading "STATEMENT <label>", uncompressed so the merge can be read back. */
function statementPdf(string $label): string
{
    $pdf = new \FPDF;
    $pdf->SetCompression(false);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, 'STATEMENT '.$label);

    return $pdf->Output('S');
}

/**
 * @param  list<array<string, mixed>>  $statements
 * @param  array<string, string>  $pdfs  statement_id => PDF bytes (default: one naming the id)
 */
function plaidListsStatements(array $statements, array $pdfs = []): \Mockery\MockInterface
{
    return test()->mock(PlaidService::class, function ($mock) use ($statements, $pdfs) {
        $mock->shouldReceive('getStatements')->andReturn(['accounts' => [['account_id' => 'plaid-acct-1', 'statements' => $statements]]]);
        $mock->shouldReceive('downloadStatement')->andReturnUsing(fn ($token, $id) => $pdfs[$id] ?? statementPdf($id));
        $mock->shouldReceive('refreshStatements')->andReturn(['request_id' => 'req-default'])->byDefault();
    });
}

function statementsAuditPage(BankAccount $account, string $start = '2025-09-01', string $end = '2026-08-31')
{
    $page = Livewire::test(AuditShow::class, ['start_date' => $start, 'end_date' => $end, 'audit_type' => 'general', 'bank_account_ids' => [$account->id]]);
    \App\Models\AuditRequest::query()->update(['status' => 'ready']);

    return $page;
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    Carbon::setTestNow('2026-10-16 12:00:00');
});

it('reads when a statement was posted, from date_posted or else its month and year', function () {
    expect(AuditShow::statementPostedOn(['statement_id' => 'a', 'month' => 9, 'year' => 2025, 'date_posted' => '2025-09-04'])->toDateString())->toBe('2025-09-04')
        ->and(AuditShow::statementPostedOn(['statement_id' => 'b', 'month' => 2, 'year' => 2026])->toDateString())->toBe('2026-02-28')
        ->and(AuditShow::statementPostedOn(['statement_id' => 'c']))->toBeNull();
});

it('downloads the period\'s statements, plus the one that closes it, as one PDF, oldest first and each once', function () {
    ['account' => $account] = statementsAudit();
    $duplicate = statementPdf('april');
    plaidListsStatements([
        ['statement_id' => 'before', 'month' => 7, 'year' => 2025, 'date_posted' => '2025-07-31'],
        ['statement_id' => 'closing', 'month' => 9, 'year' => 2026, 'date_posted' => '2026-09-03'],
        ['statement_id' => 'april', 'month' => 4, 'year' => 2026, 'date_posted' => '2026-04-30'],
        ['statement_id' => 'april-again', 'month' => 4, 'year' => 2026, 'date_posted' => '2026-04-30'],
        ['statement_id' => 'january', 'month' => 1, 'year' => 2026, 'date_posted' => '2026-01-30'],
        ['statement_id' => 'after', 'month' => 11, 'year' => 2026, 'date_posted' => '2026-11-04'],
    ], ['april' => $duplicate, 'april-again' => $duplicate]);

    $page = statementsAuditPage($account)->call('download_bank_statements')
        ->assertFileDownloaded();

    $download = $page->effects['download'];
    $pdf = base64_decode($download['content']);
    $order = collect(['january', 'april', 'closing'])->map(fn (string $label) => strpos($pdf, 'STATEMENT '.$label));

    // Months in between have no statement yet (requested), so the file says partial.
    expect($download['name'])->toEndWith('-partial.pdf')
        ->and($pdf)->toStartWith('%PDF')
        ->and($order->every(fn ($position) => $position !== false))->toBeTrue()
        ->and($order->all())->toBe($order->sort()->values()->all())
        ->and(substr_count($pdf, 'STATEMENT april'))->toBe(1)
        ->and($pdf)->not->toContain('STATEMENT before')
        ->and($pdf)->not->toContain('STATEMENT after');
});

it('finds the audit\'s months without a statement, as runs, leaving out the month still running', function () {
    $posted = ['2026-04-30', '2026-05-29', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30'];

    expect(AuditShow::missingStatementMonths($posted, '2025-08-26', '2026-08-26'))
        ->toBe([['from' => '2025-08', 'to' => '2026-03']])
        // A statement posted on the 3rd covers the month before.
        ->and(AuditShow::missingStatementMonths(['2026-02-03', '2026-04-30'], '2026-01-01', '2026-04-30'))
        ->toBe([['from' => '2026-02', 'to' => '2026-03']])
        // October 2026 is still running: not missing yet.
        ->and(AuditShow::missingStatementMonths(['2026-09-30'], '2026-09-01', '2026-10-31'))
        ->toBe([]);
});

it('names a complete year plainly and asks the bank for nothing', function () {
    ['account' => $account] = statementsAudit();
    $months = collect(range(0, 12))->map(fn ($i) => Carbon::parse('2025-08-01')->addMonths($i));
    $plaid = plaidListsStatements($months->map(fn (Carbon $month) => [
        'statement_id' => 's'.$month->format('Ym'), 'month' => $month->month, 'year' => $month->year, 'date_posted' => $month->copy()->endOfMonth()->toDateString(),
    ])->all());
    $plaid->shouldNotReceive('refreshStatements');

    $page = statementsAuditPage($account, '2025-08-26', '2026-08-26')->call('download_bank_statements')
        ->assertFileDownloaded();

    expect($page->effects['download']['name'])->toEndWith('2025-08-26-to-2026-08-26.pdf')
        ->and($page->get('statementGaps'))->toBe([]);
});

it('asks the bank for the months Plaid is missing as soon as the statements are downloaded, and says so', function () {
    ['account' => $account, 'bank' => $bank] = statementsAudit();
    $plaid = plaidListsStatements([
        ['statement_id' => 'apr', 'month' => 4, 'year' => 2026, 'date_posted' => '2026-04-30'],
        ['statement_id' => 'may', 'month' => 5, 'year' => 2026, 'date_posted' => '2026-05-29'],
        ['statement_id' => 'jun', 'month' => 6, 'year' => 2026, 'date_posted' => '2026-06-30'],
        ['statement_id' => 'jul', 'month' => 7, 'year' => 2026, 'date_posted' => '2026-07-31'],
        ['statement_id' => 'aug', 'month' => 8, 'year' => 2026, 'date_posted' => '2026-08-31'],
    ]);
    $plaid->shouldReceive('refreshStatements')
        ->once()
        ->with('access-test-not-a-real-token', '2025-08-01', '2026-04-03', Mockery::any())
        ->andReturn(['request_id' => 'req-1']);

    $page = statementsAuditPage($account, '2025-08-26', '2026-08-26')->call('download_bank_statements')
        ->assertFileDownloaded()
        ->assertSee('Bank statements missing from the download')
        ->assertSee('Citibank: Aug 2025 – Mar 2026')
        ->assertSee('waiting for the bank')
        ->assertDontSee('Request from Citibank');

    // Downloaded again before the bank answered: nothing is billed twice.
    $page->call('download_bank_statements');

    $gap = $page->get('statementGaps')[0];
    $refreshes = $bank->fresh()->plaid_options['statement_refreshes'];

    expect([$gap['bank_id'], $gap['label'], $gap['result']])->toBe([$bank->id, 'Aug 2025 – Mar 2026', null])
        ->and($gap['requested_at'])->not->toBeNull()
        ->and($refreshes)->toHaveCount(1)
        ->and([$refreshes[0]['start'], $refreshes[0]['end'], $refreshes[0]['request_id']])->toBe(['2025-08-01', '2026-04-03', 'req-1']);
});

it('asks again only when told to, after the bank failed', function () {
    ['account' => $account, 'bank' => $bank] = statementsAudit();
    $bank->update(['plaid_options' => ['statement_refreshes' => [
        ['start' => '2025-08-01', 'end' => '2026-04-03', 'requested_at' => '2026-10-16T10:00:00+00:00', 'result' => 'FAILURE'],
    ]]]);
    $plaid = plaidListsStatements([
        ['statement_id' => 'apr', 'month' => 4, 'year' => 2026, 'date_posted' => '2026-04-30'],
        ['statement_id' => 'aug', 'month' => 8, 'year' => 2026, 'date_posted' => '2026-08-31'],
    ]);
    // The download asks for May – Jul (never asked), not the failed range.
    $plaid->shouldReceive('refreshStatements')->once()->with('access-test-not-a-real-token', '2026-05-01', '2026-08-03', Mockery::any())->andReturn(['request_id' => 'req-2']);
    $plaid->shouldReceive('refreshStatements')->once()->with('access-test-not-a-real-token', '2025-08-01', '2026-04-03', Mockery::any())->andReturn(['request_id' => 'req-3']);

    statementsAuditPage($account, '2025-08-26', '2026-08-26')
        ->call('download_bank_statements')
        ->assertSee('Try again with Citibank')
        ->call('fetchMissingStatements', $bank->id)
        ->assertDontSee('Try again with Citibank');

    expect(collect($bank->fresh()->plaid_options['statement_refreshes'])->pluck('request_id')->all())->toBe([null, 'req-2', 'req-3']);
});

it('records the bank\'s answer when Plaid reports the fetch complete', function () {
    ['bank' => $bank] = statementsAudit();
    $bank->update(['plaid_options' => ['error' => false, 'statement_refreshes' => [
        ['start' => '2025-08-01', 'end' => '2026-04-03', 'requested_at' => '2026-10-16T12:00:00+00:00', 'result' => null],
    ]]]);

    $this->postJson('/webhooks/plaid', ['webhook_type' => 'STATEMENTS', 'webhook_code' => 'STATEMENTS_REFRESH_COMPLETE', 'item_id' => 'item-test', 'result' => 'SUCCESS', 'environment' => 'sandbox'])
        ->assertOk();

    $refresh = $bank->fresh()->plaid_options['statement_refreshes'][0];

    expect($refresh['result'])->toBe('SUCCESS')
        ->and($refresh['completed_at'])->not->toBeNull()
        ->and($bank->fresh()->plaid_options['error'])->toBeFalse();
});
