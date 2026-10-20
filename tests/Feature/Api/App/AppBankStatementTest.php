<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Storage::fake('local');
});

/** @return array{vendor: Vendor, admin: User, member: User} */
function appBankStatementTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

/** @return array{bank: Bank, account: BankAccount} */
function appBankStatementFixture(Vendor $vendor): array
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_access_token' => 'access-'.uniqid(), 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);

    return compact('bank', 'account');
}

it('needs a token to download a statement', function () {
    $this->getJson('/api/app/v1/banks/1/statement-url')->assertUnauthorized();
});

it('downloads the latest statement via a signed, session-less link', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appBankStatementTeam();
    Sanctum::actingAs($admin, ['app']);
    ['bank' => $bank, 'account' => $account] = appBankStatementFixture($vendor);

    $this->mock(PlaidService::class, function (MockInterface $mock) use ($account): void {
        $mock->shouldReceive('getStatements')->once()->andReturn([
            'accounts' => [[
                'account_id' => $account->plaid_account_id,
                'statements' => [[
                    'statement_id' => 'stmt_1',
                    'date_posted' => '2026-09-30',
                ]],
            ]],
        ]);
        $mock->shouldReceive('downloadStatement')->once()->andReturn('%PDF-fake-statement-bytes%');
    });

    $response = $this->getJson("/api/app/v1/banks/{$bank->id}/statement-url")->assertOk();
    $url = $response->json('data.url');
    expect($url)->toContain('/banks/statements/');

    $download = $this->get($url)->assertOk();
    expect($download->headers->get('Content-Type'))->toContain('pdf')
        ->and($download->streamedContent())->toBe('%PDF-fake-statement-bytes%');
});

it('refuses a Member downloading a statement, like the web', function () {
    ['vendor' => $vendor, 'member' => $member] = appBankStatementTeam();
    Sanctum::actingAs($member, ['app']);
    ['bank' => $bank] = appBankStatementFixture($vendor);

    $this->getJson("/api/app/v1/banks/{$bank->id}/statement-url")->assertForbidden();
});

it('returns the web\'s own error message when Plaid has no statements yet', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appBankStatementTeam();
    Sanctum::actingAs($admin, ['app']);
    ['bank' => $bank] = appBankStatementFixture($vendor);

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getStatements')->once()->andReturn(['accounts' => []]);
    });

    $this->getJson("/api/app/v1/banks/{$bank->id}/statement-url")
        ->assertStatus(422)
        ->assertJson(['message' => 'Plaid did not return any statements for this bank yet.']);
});

it('404s a bank outside the tenant', function () {
    ['admin' => $admin] = appBankStatementTeam();
    Sanctum::actingAs($admin, ['app']);

    $otherVendor = Vendor::factory()->create();
    ['bank' => $otherBank] = appBankStatementFixture($otherVendor);

    $this->getJson("/api/app/v1/banks/{$otherBank->id}/statement-url")->assertNotFound();
});
