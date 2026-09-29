<?php

use App\Http\Controllers\PlaidTransactionSyncController;
use App\Livewire\Banks\BankShow;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * A PSFCU-like bank whose Item needs a new login: two accounts sharing the
 * last four, an ITEM_LOGIN_REQUIRED error, and a last good sync on 06/23.
 *
 * @return array{bank: Bank, checking: BankAccount, savings: BankAccount}
 */
function relinkFixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Relink Co']);

    $admin = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'Admin',
        'email' => 'owner.relink-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);
    test()->actingAs($admin);

    $bank = Bank::create([
        'name' => 'PSFCU',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_106580',
        'plaid_access_token' => 'access-old',
        'plaid_item_id' => 'item-old',
        'plaid_options' => [
            'error' => ['error_code' => 'ITEM_LOGIN_REQUIRED', 'error_type' => 'ITEM_ERROR'],
            'status' => ['transactions' => ['last_successful_update' => '2026-06-23T08:26:48.371Z']],
            'next_cursor' => 'cursor-old',
        ],
    ]);

    $checking = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '6513', 'type' => 'Checking', 'plaid_account_id' => 'acct-old-checking']);
    $savings = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '6513', 'type' => 'Savings', 'plaid_account_id' => 'acct-old-savings']);

    return compact('bank', 'checking', 'savings');
}

/**
 * @return array<int, array<string, string>>
 */
function relinkLinkAccounts(): array
{
    return [
        ['id' => 'acct-new-checking', 'mask' => '6513', 'subtype' => 'checking', 'name' => 'Checking'],
        ['id' => 'acct-new-savings', 'mask' => '6513', 'subtype' => 'savings', 'name' => 'Savings'],
    ];
}

it('starts Link for a new Item with transactions only and enough history to cover the gap', function (): void {
    ['bank' => $bank] = relinkFixture();

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
        $mock->shouldReceive('createLinkToken')->once()->withArgs(fn (array $data): bool => $data['products'] === ['transactions']
            && $data['transactions'] === ['days_requested' => BankShow::RELINK_DAYS_REQUESTED]
            && ! array_key_exists('access_token', $data)
            && ! array_key_exists('statements', $data))->andReturn(['link_token' => 'link-new']);
    });

    Livewire::test(BankShow::class, ['bank' => $bank])
        ->call('plaid_link_token_relink')
        ->assertDispatched('linkTokenRelink');
});

it('moves the bank and its accounts onto the new Item and removes the old one', function (): void {
    ['bank' => $bank, 'checking' => $checking, 'savings' => $savings] = relinkFixture();
    $banksBefore = Bank::withoutGlobalScopes()->count();

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
        $mock->shouldReceive('exchangePublicToken')->once()->with('public-new')->andReturn(['access_token' => 'access-new', 'item_id' => 'item-new']);
        $mock->shouldReceive('removeItem')->once()->with('access-old')->andReturn(['request_id' => 'req-remove']);
    });

    Livewire::test(BankShow::class, ['bank' => $bank])
        ->call('plaid_link_item_relink', 'public-new', ['institution_id' => 'ins_106580', 'name' => 'Polish & Slavic Federal Credit Union'], relinkLinkAccounts(), $bank->id)
        ->assertRedirect(route('banks.show', $bank));

    $bank->refresh();

    expect($bank->plaid_access_token)->toBe('access-new')
        ->and($bank->plaid_item_id)->toBe('item-new')
        ->and($bank->error)->toBeFalse()
        ->and($bank->plaid_options)->not->toHaveKey('next_cursor')
        ->and($bank->plaid_options['relinked_through'])->toBe('2026-06-23')
        ->and($bank->plaid_options['previous_plaid_item_id'])->toBe('item-old')
        ->and($checking->fresh()->plaid_account_id)->toBe('acct-new-checking')
        ->and($savings->fresh()->plaid_account_id)->toBe('acct-new-savings')
        ->and($bank->accounts()->count())->toBe(2)
        ->and(Bank::withoutGlobalScopes()->count())->toBe($banksBefore);
});

it('refuses a login to a different bank and removes that new Item', function (): void {
    ['bank' => $bank, 'checking' => $checking] = relinkFixture();

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
        $mock->shouldReceive('exchangePublicToken')->once()->andReturn(['access_token' => 'access-wrong', 'item_id' => 'item-wrong']);
        $mock->shouldReceive('removeItem')->once()->with('access-wrong')->andReturn(['request_id' => 'req-remove']);
    });

    Livewire::test(BankShow::class, ['bank' => $bank])
        ->call('plaid_link_item_relink', 'public-wrong', ['institution_id' => 'ins_3', 'name' => 'Chase'], relinkLinkAccounts(), $bank->id);

    $bank->refresh();

    expect($bank->plaid_access_token)->toBe('access-old')
        ->and($bank->error['error_code'])->toBe('ITEM_LOGIN_REQUIRED')
        ->and($checking->fresh()->plaid_account_id)->toBe('acct-old-checking');
});

it('ignores a reconnect that another bank on the page started', function (): void {
    ['bank' => $bank] = relinkFixture();

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
        $mock->shouldNotReceive('exchangePublicToken');
        $mock->shouldNotReceive('removeItem');
    });

    Livewire::test(BankShow::class, ['bank' => $bank])
        ->call('plaid_link_item_relink', 'public-new', ['institution_id' => 'ins_106580'], relinkLinkAccounts(), $bank->id + 1000);

    expect($bank->fresh()->plaid_access_token)->toBe('access-old');
});

/**
 * @return array{bank: Bank, checking: BankAccount}
 */
function relinkedBankFixture(): array
{
    $fixture = relinkFixture();

    $fixture['bank']->forceFill([
        'plaid_access_token' => 'access-new',
        'plaid_options' => [
            'error' => false,
            'relinked_at' => now()->addHour()->toIso8601String(),
            'relinked_through' => '2026-06-23',
        ],
    ])->save();
    $fixture['checking']->forceFill(['plaid_account_id' => 'acct-new-checking'])->save();

    return $fixture;
}

it('keeps the imported row when the new Item re-delivers a transaction from the overlap', function (): void {
    ['bank' => $bank, 'checking' => $checking] = relinkedBankFixture();

    $imported = Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $checking->id,
        'transaction_date' => '2026-06-22',
        'posted_date' => '2026-06-22',
        'amount' => 84.12,
        'plaid_transaction_id' => 'old-item-txn',
        'plaid_merchant_description' => 'JEWEL OSCO',
    ]);
    $before = Transaction::withoutGlobalScopes()->count();

    $sync = app(PlaidTransactionSyncController::class);
    (fn () => $this->processAddedTransaction($bank, [
        'transaction_id' => 'new-item-txn',
        'pending_transaction_id' => null,
        'account_id' => 'acct-new-checking',
        'amount' => 84.12,
        'date' => '2026-06-22',
        'name' => 'JEWEL OSCO',
        'pending' => false,
    ], 'depository', 'test-request'))->call($sync);

    $imported->refresh();

    expect(Transaction::withoutGlobalScopes()->count())->toBe($before)
        ->and($imported->plaid_transaction_id)->toBe('new-item-txn')
        ->and($imported->details['relinked_from_plaid_transaction_id'])->toBe('old-item-txn');
});

it('imports transactions after the old Item stopped as new rows', function (): void {
    ['bank' => $bank] = relinkedBankFixture();
    $before = Transaction::withoutGlobalScopes()->count();

    $sync = app(PlaidTransactionSyncController::class);
    (fn () => $this->processAddedTransaction($bank, [
        'transaction_id' => 'new-item-july',
        'pending_transaction_id' => null,
        'account_id' => 'acct-new-checking',
        'amount' => 84.12,
        'date' => '2026-07-15',
        'name' => 'JEWEL OSCO',
        'pending' => false,
    ], 'depository', 'test-request'))->call($sync);

    expect(Transaction::withoutGlobalScopes()->count())->toBe($before + 1)
        ->and(Transaction::withoutGlobalScopes()->where('plaid_transaction_id', 'new-item-july')->exists())->toBeTrue();
});

it('starts a reconnected bank\'s sync a few days before the old Item\'s last delivery', function (): void {
    ['bank' => $bank] = relinkedBankFixture();

    $sync = app(PlaidTransactionSyncController::class);

    expect((fn () => $this->relinkFloor($bank))->call($sync))->toBe('2026-06-20');
});
