<?php

use App\Livewire\Transactions\VendorTransactionEditModal;
use App\Livewire\Transactions\VendorTransactionsPanel;
use App\Models\Bank;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reloads vendor transaction rows with the selected vendor relationship', function () {
    $selectedVendor = Vendor::factory()->create([
        'business_name' => 'Selected Vendor LLC',
    ]);

    $vendorTransaction = VendorTransaction::create([
        'vendor_id' => null,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'ACME',
        'options' => json_encode('/i'),
    ]);

    Livewire::test(VendorTransactionEditModal::class)
        ->call('editVendorTransaction', $vendorTransaction->id)
        ->set('vendor_id', (string) $selectedVendor->id)
        ->call('save');

    $vendorTransaction->refresh();

    $panel = new VendorTransactionsPanel();
    $panel->mount();

    expect($vendorTransaction->vendor)->not->toBeNull()
        ->and($vendorTransaction->vendor->is($selectedVendor))->toBeTrue()
        ->and(collect($panel->vendor_transactions)->firstWhere('id', $vendorTransaction->id)['vendor']['id'])->toBe($selectedVendor->id);
});

it('maps deposit check values to readable labels for the table', function () {
    $vendorTransaction = VendorTransaction::create([
        'vendor_id' => null,
        'deposit_check' => 3,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'TRANSFER TEST',
        'options' => json_encode('/i'),
    ]);

    Livewire::test(VendorTransactionEditModal::class)
        ->call('editVendorTransaction', $vendorTransaction->id)
        ->set('deposit_check', '2')
        ->call('save');

    $vendorTransaction->refresh();

    $panel = new VendorTransactionsPanel();
    $panel->mount();
    $row = collect($panel->vendor_transactions)->firstWhere('id', $vendorTransaction->id);

    expect($vendorTransaction->deposit_check)->toBe(2)
        ->and($row['deposit_check_label'])->toBe('Check Paid');
});

it('sorts vendor transaction rows by vendor business name', function () {
    $zVendor = Vendor::factory()->create([
        'business_name' => 'Zulu Services',
    ]);

    $aVendor = Vendor::factory()->create([
        'business_name' => 'Alpha Plumbing',
    ]);

    VendorTransaction::create([
        'vendor_id' => $zVendor->id,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'Z transaction',
        'options' => json_encode('/i'),
    ]);

    VendorTransaction::create([
        'vendor_id' => $aVendor->id,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'A transaction',
        'options' => json_encode('/i'),
    ]);

    $component = new VendorTransactionsPanel();
    $component->mount();

    $vendorNames = collect($component->vendor_transactions)
        ->pluck('vendor.business_name')
        ->all();

    expect($vendorNames)->toBe(['Alpha Plumbing', 'Zulu Services']);
});

it('links plaid institution ids to banks through the vendor transaction relationship', function () {
    $vendor = Vendor::factory()->create();

    $bank = Bank::create([
        'name' => 'First National',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_123',
    ]);

    $vendorTransaction = VendorTransaction::create([
        'vendor_id' => null,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'BANK TEST',
        'options' => json_encode('/i'),
    ]);

    Livewire::test(VendorTransactionEditModal::class)
        ->call('editVendorTransaction', $vendorTransaction->id)
        ->set('plaid_inst_id', $bank->plaid_ins_id)
        ->call('save');

    $vendorTransaction->refresh();

    $panel = new VendorTransactionsPanel();
    $panel->mount();
    $row = collect($panel->vendor_transactions)->firstWhere('id', $vendorTransaction->id);

    expect($vendorTransaction->bank)->not->toBeNull()
        ->and($vendorTransaction->bank->is($bank))->toBeTrue()
        ->and($row['bank']['name'])->toBe('First National')
        ->and($row['bank']['plaid_ins_id'])->toBe('ins_123');
});

it('defers vendor transaction table loading until requested', function () {
    VendorTransaction::create([
        'vendor_id' => null,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'LAZY LOAD TEST',
        'options' => json_encode('/i'),
    ]);

    $component = new VendorTransactionsPanel();
    $component->mount();

    expect($component->placeholder()->render())->toContain('Loading vendor transactions...');
});

it('creates a new vendor transaction from the page form with vendor and transfer type', function () {
    $vendor = Vendor::factory()->create([
        'business_name' => 'Transfer Vendor',
    ]);

    $bank = Bank::create([
        'name' => 'Zelle Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_transfer',
    ]);

    $component = new VendorTransactionsPanel();
    $component->mount();
    $component->new_vendor_transaction = [
        'vendor_id' => (string) $vendor->id,
        'desc' => 'ZELLE PAYMENT',
        'deposit_check' => '3',
        'amount_sign' => '1',
        'plaid_inst_id' => $bank->plaid_ins_id,
        'options' => 'zelle',
    ];

    $component->createVendorTransaction();

    $created = VendorTransaction::query()->latest('id')->first();

    expect($created)->not->toBeNull()
        ->and($created->vendor_id)->toBe($vendor->id)
        ->and($created->deposit_check)->toBe(3)
        ->and($created->amount_sign)->toBe(1)
        ->and($created->plaid_inst_id)->toBe('ins_transfer')
        ->and($created->desc)->toBe('ZELLE PAYMENT')
        ->and($created->options)->toBe(json_encode('zelle/i'))
        ->and($component->new_vendor_transaction['deposit_check'])->toBe('3')
        ->and(collect($component->vendor_transactions)->firstWhere('id', $created->id)['vendor']['business_name'])->toBe('Transfer Vendor');
});

it('prevents duplicate vendor transactions from being created in the form', function () {
    $vendor = Vendor::factory()->create([
        'business_name' => 'Duplicate Vendor',
    ]);

    $bank = Bank::create([
        'name' => 'Duplicate Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_dup',
    ]);

    $component = new VendorTransactionsPanel();
    $component->mount();

    $component->new_vendor_transaction = [
        'vendor_id' => (string) $vendor->id,
        'desc' => 'ACH TRANSFER',
        'deposit_check' => '3',
        'amount_sign' => '1',
        'plaid_inst_id' => $bank->plaid_ins_id,
        'options' => 'ach',
    ];
    $component->createVendorTransaction();

    $component->new_vendor_transaction = [
        'vendor_id' => (string) $vendor->id,
        'desc' => '  ACH TRANSFER  ',
        'deposit_check' => '3',
        'amount_sign' => '1',
        'plaid_inst_id' => $bank->plaid_ins_id,
        'options' => ' ach ',
    ];
    $component->createVendorTransaction();

    expect(VendorTransaction::query()->count())->toBe(1)
        ->and($component->getErrorBag()->has('new_vendor_transaction.desc'))->toBeTrue();
});

it('deletes a vendor transaction from the edit modal', function () {
    $vendorTransaction = VendorTransaction::create([
        'vendor_id' => null,
        'deposit_check' => 3,
        'amount_sign' => 1,
        'plaid_inst_id' => 'ins_delete',
        'desc' => 'DELETE TEST',
        'options' => json_encode('delete/i'),
    ]);

    Livewire::test(VendorTransactionEditModal::class)
        ->call('editVendorTransaction', $vendorTransaction->id)
        ->call('delete');

    expect(VendorTransaction::query()->whereKey($vendorTransaction->id)->exists())->toBeFalse();
});

it('renders the match vendor page with a deferred vendor transactions panel', function () {
    $vendor = Vendor::factory()->create();
    $vendor->forceFill([
        'registration' => ['registered' => true],
    ])->save();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test-user@example.com',
        'cell_phone' => '5551234567',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);

    $user->vendors()->attach($vendor->id, [
        'role_id' => 1,
    ]);

    $this->withSession([
        'is_admin_login_as' => true,
    ])
        ->actingAs($user)
        ->get(route('transactions.match_vendor'))
        ->assertOk()
        ->assertSee('Loading vendor transactions...');
});