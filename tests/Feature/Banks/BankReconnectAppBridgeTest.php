<?php

use App\Livewire\Banks\BankShow;
use App\Models\Bank;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Covers the Hive Contractors app's bank-reconnect flow: the app opens
 * /banks/{bank} inside its in-app browser (via the signed web-session
 * hand-off) with a `return_to` of its own "hivecontractors://…" deep link,
 * same pattern as the mailbox connect (CompanyEmailAppReturnToTest). Plaid
 * Link itself is unchanged — this only covers the bridge back to the app:
 * BankShow::render() passing a validated `returnTo` into the view, and the
 * `bankReconnected` event that show.blade.php's @script redirects on, fired
 * once plaid_link_item_update()/plaid_link_item_relink() actually succeed.
 */
function bankBridgeAdminUser(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Bank Bridge Co']);
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Bank',
        'last_name' => 'Admin',
        'email' => 'bank-bridge-admin-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    return compact('vendor', 'admin');
}

function bankBridgeErroredBank(Vendor $vendor): Bank
{
    return Bank::create([
        'name' => 'Bridge Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_bridge',
        'plaid_access_token' => 'access-old',
        'plaid_item_id' => 'item-old',
        'plaid_options' => ['error' => ['error_code' => 'ITEM_LOGIN_REQUIRED', 'error_type' => 'ITEM_ERROR']],
    ]);
}

it('passes a validated return_to into the view, and drops an invalid one', function () {
    ['vendor' => $vendor, 'admin' => $admin] = bankBridgeAdminUser();
    $bank = bankBridgeErroredBank($vendor);

    $this->actingAs($admin)
        ->get('/banks/'.$bank->id.'?return_to='.urlencode('hivecontractors://banks/'.$bank->id))
        ->assertOk()
        ->assertSee('bankReconnected', false);

    $this->actingAs($admin)
        ->get('/banks/'.$bank->id.'?return_to='.urlencode('https://evil.test'))
        ->assertOk()
        ->assertDontSee('bankReconnected', false)
        ->assertDontSee('evil.test', false);

    $this->actingAs($admin)
        ->get('/banks/'.$bank->id)
        ->assertOk()
        ->assertDontSee('bankReconnected', false);
});

it('dispatches bankReconnected when the update-mode repair succeeds', function () {
    ['vendor' => $vendor, 'admin' => $admin] = bankBridgeAdminUser();
    $bank = bankBridgeErroredBank($vendor);

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('processPlaidItem')->once()->andReturn(new Bank());
        $mock->shouldReceive('refreshItemStatus')->once();
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
    });

    Livewire::actingAs($admin)->test(BankShow::class, ['bank' => $bank])
        ->call('plaid_link_item_update', 'public-token', ['institution_id' => 'ins_bridge'], [], $bank->id)
        ->assertDispatched('bankReconnected');
});

it('does not dispatch bankReconnected when the update-mode repair fails', function () {
    ['vendor' => $vendor, 'admin' => $admin] = bankBridgeAdminUser();
    $bank = bankBridgeErroredBank($vendor);

    Livewire::actingAs($admin)->test(BankShow::class, ['bank' => $bank])
        ->call('plaid_link_item_update', null, null, null, $bank->id)
        ->assertNotDispatched('bankReconnected');
});
