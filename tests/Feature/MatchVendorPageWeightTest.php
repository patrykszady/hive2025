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
 * stalled before all cards had painted. These pin the diet: pickers show a
 * scroll-page of matches, tables paginate, and nothing heavy is public state.
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

it('shows a card picker one scroll-page of vendors at a time, searched on the server', function () {
    pageWeightActor();
    manyVendors(40);
    Vendor::factory()->create(['business_name' => 'Menards Store 3120']);

    $component = new MatchVendor();

    expect($component->vendorOptions('txn_0'))->toHaveCount(MatchVendor::VENDOR_OPTION_LIMIT)
        ->and($component->hasMoreVendorOptions('txn_0'))->toBeTrue();

    $component->vendor_search = ['txn_0' => 'menard'];
    $names = $component->vendorOptions('txn_0')->pluck('business_name')->all();

    expect($names)->toBe(['Menards Store 3120'])
        ->and($component->hasMoreVendorOptions('txn_0'))->toBeFalse();

    // Scrolling the open dropdown asks for the next page — for THAT picker only.
    $component->vendor_search = [];
    $component->loadMoreVendorOptions('txn_0');
    expect($component->vendorOptions('txn_0')->count())->toBeGreaterThan(MatchVendor::VENDOR_OPTION_LIMIT)
        ->and($component->vendorOptions('exp_0'))->toHaveCount(MatchVendor::VENDOR_OPTION_LIMIT);
});

it('keeps a card\'s selected vendor in its options even when the search would hide it', function () {
    pageWeightActor();
    manyVendors(30);
    $chosen = Vendor::factory()->create(['business_name' => 'Zzz Chosen Vendor']);

    $component = new MatchVendor();
    $component->match_merchant_names = [2 => ['match_desc' => 'X', 'vendor_id' => (string) $chosen->id]];
    $component->vendor_search = ['txn_2' => 'Vendor 00'];

    expect($component->vendorOptions('txn_2')->first()->id)->toBe($chosen->id)
        // The static NEW/DEPOSIT/... choices are not vendors and add nothing.
        ->and((new MatchVendor())->vendorOptions('txn_0')->pluck('business_name'))->not->toContain('NEW');
});

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

it('renders the page without printing the whole vendor list into every card', function () {
    $user = pageWeightActor();
    manyVendors(60, 'Bulk');

    $html = Livewire::actingAs($user)->test(MatchVendor::class)->html();

    // island_lazy() is off in tests, so both islands render inline. Each
    // picker shows a scroll-page (25) — never the 60 bulk vendors.
    expect(substr_count($html, 'Bulk 0'))->toBeLessThanOrEqual(MatchVendor::VENDOR_OPTION_LIMIT)
        ->and($html)->toContain('Loading vendor transactions...');
});

it('searches a card picker on the server from inside the island', function () {
    $user = pageWeightActor();
    manyVendors(30);
    Vendor::factory()->create(['business_name' => 'Menards Store 3120']);

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

    Livewire::actingAs($user)
        ->test(MatchVendor::class)
        ->assertSee('MENARDS #3120')
        ->assertSee('Vendor 001')
        ->call('searchVendors', 'txn_0', 'menard')
        ->assertSee('Menards Store 3120')
        ->assertDontSee('Vendor 001');
});
