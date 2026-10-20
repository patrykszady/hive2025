<?php

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User} */
function appReceiptItemTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return compact('vendor', 'admin');
}

function appReceiptItemExpense(Vendor $vendor, array $overrides = []): Expense
{
    $merchant = Vendor::factory()->create(['business_name' => 'Menards']);
    // VendorScope hides a merchant vendor from the viewer unless it's
    // linked to their own — link it so $expense->vendor->business_name
    // resolves to the real name instead of the scope's "NO VENDOR" default.
    $vendor->vendors()->attach($merchant->id);

    return Expense::create(array_merge([
        'amount' => 100.00,
        'date' => now()->format('Y-m-d'),
        'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ], $overrides));
}

it('needs a token for a receipt line item\'s detail', function () {
    $this->getJson('/api/app/v1/expenses/1/receipts/1/items/0')->assertUnauthorized();
});

it('shows a receipt line item\'s detail, enriched with its image/product/area', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptItemTeam();
    $expense = appReceiptItemExpense($vendor, ['amount' => 24.94]);
    $receipt = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id.'-receipt.pdf',
        'receipt_items' => [
            'items' => [
                ['Description' => '2x4 Stud', 'Price' => 5.98, 'Quantity' => 4, 'TotalPrice' => 23.92, 'VendorCode' => 'SKU-1'],
            ],
            'total' => 24.94,
        ],
    ]);
    ReceiptLineItemDesc::create([
        'expense_receipt_id' => $receipt->id,
        'item_index' => 0,
        'vendor_id' => $expense->vendor_id,
        'product_image_url' => 'https://images.example.test/stud.jpg',
        'product_url' => 'https://products.example.test/stud',
        'area' => ['Framing'],
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson("/api/app/v1/expenses/{$expense->id}/receipts/{$receipt->id}/items/0")->assertOk();

    $response->assertJsonPath('data.Description', '2x4 Stud')
        ->assertJsonPath('data.image_url', 'https://images.example.test/stud.jpg')
        ->assertJsonPath('data.product_url', 'https://products.example.test/stud')
        ->assertJsonPath('data.Area', ['Framing'])
        ->assertJsonPath('data._vendor_name', 'Menards');
});

it('includes status/ETA history for a material order\'s line item', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptItemTeam();
    $expense = appReceiptItemExpense($vendor);
    $receipt = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id.'-order.pdf',
        'is_material_order' => true,
        'receipt_items' => [
            'items' => [
                ['Description' => 'Cabinet', 'Status' => 'Shipped'],
            ],
        ],
    ]);

    activity('materials')
        ->performedOn($receipt)
        ->withProperties(['items' => [
            ['item_index' => 0, 'old_status' => 'Open', 'new_status' => 'Shipped'],
        ]])
        ->log('status changed');

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson("/api/app/v1/expenses/{$expense->id}/receipts/{$receipt->id}/items/0")->assertOk();

    $response->assertJsonCount(1, 'data._history')
        ->assertJsonPath('data._history.0.old_status', 'Open')
        ->assertJsonPath('data._history.0.new_status', 'Shipped');
});

it('404s a missing item index', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptItemTeam();
    $expense = appReceiptItemExpense($vendor);
    $receipt = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id.'-receipt.pdf',
        'receipt_items' => ['items' => [['Description' => 'Only item']]],
    ]);

    Sanctum::actingAs($admin, ['app']);

    $this->getJson("/api/app/v1/expenses/{$expense->id}/receipts/{$receipt->id}/items/5")->assertNotFound();
});

it('403s a receipt item on an expense outside the viewer\'s access', function () {
    ['admin' => $admin] = appReceiptItemTeam();
    $otherVendor = Vendor::factory()->create();
    $notMine = appReceiptItemExpense($otherVendor, ['paid_by' => null]);
    $receipt = ExpenseReceipts::create([
        'expense_id' => $notMine->id,
        'receipt_filename' => $notMine->id.'-receipt.pdf',
        'receipt_items' => ['items' => [['Description' => 'Hidden item']]],
    ]);

    Sanctum::actingAs($admin, ['app']);

    $this->getJson("/api/app/v1/expenses/{$notMine->id}/receipts/{$receipt->id}/items/0")->assertForbidden();
});
