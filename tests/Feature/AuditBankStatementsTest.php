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
// "Download bank statements" on an audit: Plaid's /statements/list gives each
// statement month, year and date_posted, no start or end date. Reading
// end_date threw, and the button only ever said "An error occurred"
// (2026-10-15).
// =========================================================================

/** @return array{account: BankAccount} */
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

    return ['account' => $account];
}

/** @param  list<array<string, mixed>>  $statements */
function plaidListsStatements(array $statements): void
{
    test()->mock(PlaidService::class, function ($mock) use ($statements) {
        $mock->shouldReceive('getStatements')->andReturn(['accounts' => [['account_id' => 'plaid-acct-1', 'statements' => $statements]]]);
        $mock->shouldReceive('downloadStatement')->andReturnUsing(fn ($token, $id) => "%PDF-1.4 statement {$id}");
    });
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('reads when a statement was posted, from date_posted or else its month and year', function () {
    expect(AuditShow::statementPostedOn(['statement_id' => 'a', 'month' => 9, 'year' => 2025, 'date_posted' => '2025-09-04'])->toDateString())->toBe('2025-09-04')
        ->and(AuditShow::statementPostedOn(['statement_id' => 'b', 'month' => 2, 'year' => 2026])->toDateString())->toBe('2026-02-28')
        ->and(AuditShow::statementPostedOn(['statement_id' => 'c']))->toBeNull();
});

it('downloads the statements posted in the audit period, plus the one that closes it, as a zip', function () {
    ['account' => $account] = statementsAudit();
    plaidListsStatements([
        ['statement_id' => 'before', 'month' => 7, 'year' => 2025, 'date_posted' => '2025-07-03'],
        ['statement_id' => 'inside', 'month' => 1, 'year' => 2026, 'date_posted' => '2026-01-05'],
        ['statement_id' => 'closing', 'month' => 9, 'year' => 2026, 'date_posted' => '2026-09-03'],
        ['statement_id' => 'after', 'month' => 11, 'year' => 2026, 'date_posted' => '2026-11-04'],
    ]);

    $page = Livewire::test(AuditShow::class, ['start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'audit_type' => 'general', 'bank_account_ids' => [$account->id]]);
    \App\Models\AuditRequest::query()->update(['status' => 'ready']);

    $page->call('download_bank_statements')
        ->assertFileDownloaded()
        ->assertNotDispatched('toast');

    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, base64_decode($page->effects['download']['content']));
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
    $zip->close();
    @unlink($path);

    expect($names)->toBe(['citibank_1828_2026-01-05.pdf', 'citibank_1828_2026-09-03.pdf']);
});
