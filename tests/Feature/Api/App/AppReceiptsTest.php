<?php

use App\Models\AutoReceiptEmailBatch;
use App\Models\AutoReceiptEmailBatchItem;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User} */
function appReceiptsAdmin(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $admin->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    return compact('vendor', 'admin');
}

function appReceiptsMember(Vendor $vendor): User
{
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $member->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);

    return $member;
}

/** @return array{batch: AutoReceiptEmailBatch, receipt: ExpenseReceipts, expense: Expense} */
function appReceiptsBatchWithOneReceipt(Vendor $vendor, User $admin, array $receiptItems = []): array
{
    $merchant = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => 'Home Depot']);
    $client = Client::factory()->create();
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen Remodel', 'client_id' => $client->id,
        'address' => '123 Main St', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $expense = Expense::create([
        'date' => '2026-09-01', 'amount' => 42.50, 'vendor_id' => $merchant->id, 'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]);

    $receipt = ExpenseReceipts::forceCreate([
        'expense_id' => $expense->id,
        'receipt_filename' => 'receipt-1.jpg',
        'receipt_items' => $receiptItems,
    ]);

    $batch = AutoReceiptEmailBatch::create([
        'message_id' => 'msg-'.uniqid(), 'company_email_id' => null, 'belongs_to_vendor_id' => $vendor->id,
        'from_email' => 'receipts@homedepot.com', 'subject' => 'Your receipt',
        'email_received_at' => '2026-09-01 10:00:00', 'attachment_count' => 1, 'processed_receipt_count' => 1,
    ]);

    AutoReceiptEmailBatchItem::create(['batch_id' => $batch->id, 'expense_receipt_id' => $receipt->id, 'attachment_index' => 0]);

    return compact('batch', 'receipt', 'expense');
}

it('needs a token for the auto-receipts queue', function () {
    $this->getJson('/api/app/v1/receipts/auto')->assertUnauthorized();
});

it('refuses the queue to a Member — mirrors the web\'s Expense::create gate on the "Recent Auto Receipts" button', function () {
    ['vendor' => $vendor] = appReceiptsAdmin();
    $member = appReceiptsMember($vendor);

    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/receipts/auto')->assertForbidden();
});

it('lists this vendor\'s auto-ingested receipts, newest batch first, with a signed image URL', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptsAdmin();
    ['receipt' => $receipt, 'expense' => $expense] = appReceiptsBatchWithOneReceipt($vendor, $admin, [
        'merchant_name' => 'Home Depot', 'purchase_order' => 'PO-1',
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/receipts/auto')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $row = $response->json('data.0');
    expect($row['id'])->toBe($receipt->id)
        ->and($row['batch_number'])->toBe(1)
        ->and($row['merchant_name'])->toBe('Home Depot')
        ->and($row['expense']['id'])->toBe($expense->id)
        ->and((float) $row['expense']['amount'])->toBe(42.5)
        ->and($row['image_url'])->toContain('/expenses/receipts/'.$receipt->id.'/signed')
        ->and($row['image_url'])->toContain('signature=');
});

it('never shows another tenant\'s auto-ingested receipts, even sitting in the same database', function () {
    // The app is GS Construction's own team only (app.access pins
    // primary_vendor_id to 1), but the underlying `hive2025` database is
    // shared across every tenant Hive hosts — a batch tagged with a
    // DIFFERENT vendor's belongs_to_vendor_id must never leak through this
    // query just because it exists in the same table.
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptsAdmin();
    $otherCompany = Vendor::factory()->create(['business_type' => 'Sub']);
    appReceiptsBatchWithOneReceipt($otherCompany, $admin);
    appReceiptsBatchWithOneReceipt($vendor, $admin);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/receipts/auto')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(collect($response->json('data'))->pluck('expense.vendor_name'))->not->toContain(null);
});

it('shows a single receipt\'s detail with its OCR summary and linked expense', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptsAdmin();
    ['receipt' => $receipt] = appReceiptsBatchWithOneReceipt($vendor, $admin, [
        'merchant_name' => 'Home Depot',
        'purchase_order' => 'PO-1',
        'invoice_number' => 'INV-1',
        'total' => 24.99,
        'items' => [['Description' => '2x4 Lumber', 'ProductCode' => 'SKU-1', 'Price' => 24.99, 'Quantity' => 1, 'TotalPrice' => 24.99]],
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson("/api/app/v1/receipts/auto/{$receipt->id}")->assertOk();

    expect($response->json('data.merchant_name'))->toBe('Home Depot')
        ->and($response->json('data.purchase_order'))->toBe('PO-1')
        ->and($response->json('data.invoice_number'))->toBe('INV-1')
        ->and((float) $response->json('data.total'))->toBe(24.99)
        ->and($response->json('data.items.0.Description'))->toBe('2x4 Lumber')
        ->and($response->json('data.siblings'))->toBe([])
        ->and($response->json('data.expense.web_path'))->toStartWith('/expenses/');
});

it('lists sibling receipts of the same expense, newest first, excluding the current one', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptsAdmin();
    ['receipt' => $receipt, 'expense' => $expense] = appReceiptsBatchWithOneReceipt($vendor, $admin, [
        'merchant_name' => 'Home Depot',
    ]);

    $sibling = ExpenseReceipts::forceCreate([
        'expense_id' => $expense->id,
        'receipt_filename' => 'receipt-2.jpg',
        'receipt_items' => ['merchant_name' => 'Home Depot'],
        'is_material_order' => true,
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson("/api/app/v1/receipts/auto/{$receipt->id}")->assertOk();

    expect($response->json('data.siblings'))->toHaveCount(1)
        ->and($response->json('data.siblings.0.id'))->toBe($sibling->id)
        ->and($response->json('data.siblings.0.is_material_order'))->toBeTrue();
});

it('404s a receipt that does not exist, or belongs to another tenant\'s expense', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appReceiptsAdmin();
    $otherCompany = Vendor::factory()->create(['business_type' => 'Sub']);
    ['receipt' => $foreignReceipt] = appReceiptsBatchWithOneReceipt($otherCompany, $admin);

    Sanctum::actingAs($admin, ['app']);
    $this->getJson("/api/app/v1/receipts/auto/{$foreignReceipt->id}")->assertNotFound();
    $this->getJson('/api/app/v1/receipts/auto/999999')->assertNotFound();
});
