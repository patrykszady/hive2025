<?php

use App\Livewire\Transactions\MatchVendor;
use App\Livewire\Transactions\VendorTransactionsPanel;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Match Vendor page used to print every vendor as an option in every
 * card and carry every list in the Livewire snapshot — 17MB of HTML that
 * stalled before all cards had painted. These pin the diet: the page prints
 * no vendor options at all (every card's picker is <x-vendor-picker>, which
 * fetches one shared list and filters it in the browser), tables paginate,
 * and nothing heavy is public state.
 */
function pageWeightActor(): User
{
    $vendor = Vendor::factory()->create(['business_name' => 'Fixture Holding Co', 'business_type' => 'Sub']);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = User::query()->create([
        'first_name' => 'Test', 'last_name' => 'User',
        'email' => 'page-weight@example.test', 'cell_phone' => '5551234570',
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    test()->actingAs($user);

    return $user;
}

function manyVendors(int $count, string $prefix = 'Vendor'): void
{
    for ($i = 1; $i <= $count; $i++) {
        Vendor::factory()->create(['business_name' => sprintf('%s %03d', $prefix, $i)]);
    }
}

it('paginates and searches the vendor transaction rules', function () {
    pageWeightActor();
    $acme = Vendor::factory()->create(['business_name' => 'Acme Supply']);
    for ($i = 1; $i <= 30; $i++) {
        VendorTransaction::create(['vendor_id' => $acme->id, 'desc' => "ACME RULE {$i}", 'options' => json_encode('/i')]);
    }
    VendorTransaction::create(['vendor_id' => null, 'deposit_check' => 3, 'desc' => 'ZELLE OUT', 'options' => json_encode('/i')]);

    $panel = new VendorTransactionsPanel();
    $panel->mount();

    $page = $panel->vendor_transactions();
    expect($page->total())->toBe(31)
        ->and($page->perPage())->toBe(VendorTransactionsPanel::PER_PAGE)
        ->and(count($page->items()))->toBe(VendorTransactionsPanel::PER_PAGE)
        // Named vendors first, the vendor-less rule last.
        ->and($page->getCollection()->first()['vendor']['business_name'])->toBe('Acme Supply');

    $panel->search = 'zelle';
    $found = $panel->vendor_transactions();
    expect($found->total())->toBe(1)
        ->and($found->getCollection()->first()['desc'])->toBe('ZELLE OUT');
});

it('renders the page without printing any vendor into any card', function () {
    $user = pageWeightActor();
    manyVendors(60, 'Bulk');

    // One unmatched charge, so the page has a card (and a picker) to render.
    $bank = Bank::create(['name' => 'Fixture Bank', 'vendor_id' => $user->primary_vendor_id, 'plaid_ins_id' => 'ins_fixture']);
    $account = BankAccount::create([
        'vendor_id' => $user->primary_vendor_id, 'bank_id' => $bank->id, 'account_number' => '0001',
        'plaid_account_id' => 'acc_fixture_'.uniqid(), 'type' => 'credit',
    ]);
    Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 45.00, 'bank_account_id' => $account->id,
        'plaid_merchant_name' => 'MENARDS', 'plaid_merchant_description' => 'MENARDS #3120',
        'details' => ['category' => ['Shops'], 'payment_channel' => 'in store'],
    ]);

    $html = Livewire::actingAs($user)->test(MatchVendor::class)->html();

    expect($html)->toContain('MENARDS #3120');

    // island_lazy() is off in tests, so both islands render inline. No card
    // carries vendor options: the picker fetches the shared list itself.
    expect(substr_count($html, 'Bulk 0'))->toBe(0)
        ->and($html)->toContain('data-vendor-picker')
        ->and($html)->toContain('picker-options')
        ->and($html)->toContain('Loading vendor transactions...');
});
