<?php

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

/** @return array{vendor: Vendor, user: User} */
function appJobsMaterialsIndexTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsMaterialsIndexProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Materials Index Test',
        'client_id' => $client->id,
        'address' => '1 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

it('includes each item\'s receipt_id and item_index, so the app can open an item\'s own detail', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsMaterialsIndexTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $project = appJobsMaterialsIndexProject($vendor);

    $expense = Expense::factory()->create([
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    $receipt = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'is_material_order' => true,
        'receipt_items' => [
            'invoice_number' => 'INV-77',
            'items' => [
                ['name' => 'Faucet', 'Status' => 'Open'],
                ['name' => 'Sink', 'Status' => 'Received'],
            ],
        ],
    ]);

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/materials")->assertOk();

    $order = collect($response->json('data'))->firstWhere('expense_id', $expense->id);
    expect($order)->not->toBeNull();
    expect($order['items'])->toHaveCount(2);

    foreach ($order['items'] as $index => $item) {
        expect($item['receipt_id'])->toBe($receipt->id)
            ->and($item['item_index'])->toBe($index);
    }
});

it('serves that item\'s full detail off the same receipt_id/item_index the materials list returned', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsMaterialsIndexTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $project = appJobsMaterialsIndexProject($vendor);

    $expense = Expense::factory()->create([
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'is_material_order' => true,
        'receipt_items' => [
            'items' => [
                ['name' => 'Faucet', 'Description' => 'Chrome Faucet', 'Status' => 'Open'],
            ],
        ],
    ]);

    $listed = $this->getJson("/api/app/v1/projects/{$project->id}/materials")->assertOk();
    $item = collect($listed->json('data'))->firstWhere('expense_id', $expense->id)['items'][0];

    $this->getJson("/api/app/v1/expenses/{$expense->id}/receipts/{$item['receipt_id']}/items/{$item['item_index']}")
        ->assertOk()
        ->assertJsonPath('data.Description', 'Chrome Faucet');
});
