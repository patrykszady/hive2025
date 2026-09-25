<?php

use App\Livewire\Receipts\ReceiptsIndex;
use App\Models\Receipt;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * /receipts (the global email-receipt-PARSING setup, used by
 * CompanyEmailController to auto-match incoming emails) was an unpaginated
 * `->get()` (1.4MB body), and edit/store/delete had no authorization at all
 * even though it edits shared, global config. These pin: the list
 * paginates, and only the platform superadmin (id 1, same convention as
 * AgentsIndex) can write.
 */
function perf_receiptsSuperadmin(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    // AgentsIndex-style convention: auth()->id() === 1 is the superadmin.
    // The test DB seeder (RefreshDatabase seeds automatically here) already
    // creates id 1 — reuse that row instead of colliding with it.
    $user = User::find(1);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->save();
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user->fresh();
}

function perf_receiptsOrdinaryAdmin(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Ordinary', 'last_name' => 'Admin',
        'email' => 'ordinary-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_makeReceiptPattern(Vendor $vendor, string $fromAddress): Receipt
{
    return Receipt::create([
        'vendor_id' => $vendor->id,
        'from_type' => 2,
        'from_address' => $fromAddress,
        'receipt_type' => 1,
        'options' => [],
    ]);
}

it('paginates the receipt-pattern list instead of loading every row', function () {
    $user = perf_receiptsSuperadmin();
    $this->actingAs($user);

    $vendor = Vendor::factory()->create(['business_type' => 'Retail']);
    for ($i = 0; $i < 30; $i++) {
        perf_makeReceiptPattern($vendor, "sender{$i}@example.test");
    }

    $component = Livewire::test(ReceiptsIndex::class);
    $paginator = $component->instance()->receipts();

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(30)
        ->and(count($paginator->items()))->toBe(25); // first page only, not all 30
});

it('blocks a non-superadmin from editing, creating or deleting receipt patterns', function () {
    $user = perf_receiptsOrdinaryAdmin();
    $vendor = Vendor::factory()->create(['business_type' => 'Retail']);
    $receipt = perf_makeReceiptPattern($vendor, 'blocked@example.test');
    $this->actingAs($user);

    Livewire::test(ReceiptsIndex::class)
        ->call('edit', $receipt->id)
        ->assertForbidden();

    Livewire::test(ReceiptsIndex::class)
        ->set('vendor_id', $vendor->id)
        ->set('from_address', 'new@example.test')
        ->call('store')
        ->assertForbidden();

    Livewire::test(ReceiptsIndex::class)
        ->call('delete', $receipt->id)
        ->assertForbidden();

    expect(Receipt::count())->toBe(1)
        ->and(Receipt::first()->from_address)->toBe('blocked@example.test');
});

it('lets the superadmin edit and delete receipt patterns', function () {
    $user = perf_receiptsSuperadmin();
    $vendor = Vendor::factory()->create(['business_type' => 'Retail']);
    $receipt = Receipt::create([
        'vendor_id' => $vendor->id, 'from_type' => 2, 'from_address' => 'editable@example.test',
        'receipt_type' => 1, 'options' => ['receipt_start' => 'Total'],
    ]);
    $this->actingAs($user);

    // Clearing every option used to crash: a query-builder update skipped
    // the array casts, and the success toast lacked its required text.
    Livewire::test(ReceiptsIndex::class)
        ->call('edit', $receipt->id)
        ->assertSet('editing_id', $receipt->id)
        ->set('from_address', 'updated@example.test')
        ->set('options', [])
        ->call('store')
        ->assertHasNoErrors();

    expect($receipt->fresh()->from_address)->toBe('updated@example.test');

    Livewire::test(ReceiptsIndex::class)
        ->call('delete', $receipt->id)
        ->assertHasNoErrors();

    expect(Receipt::count())->toBe(0);
});
