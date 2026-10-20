<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ContentUnderstandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Storage::fake('files');

    // Never a real Content Understanding / Azure call — see CheckImageUploadTest's
    // analyzerReads(); the upload itself succeeds whatever the analyzer returns
    // (CheckImageUpload::attach() swallows analyzer failures), so a bare stub
    // of the shape CheckImageAnalyzer expects is enough.
    test()->mock(ContentUnderstandingService::class, fn ($mock) => $mock->shouldReceive('analyzeBinary')->andReturn([
        'result' => ['contents' => [['fields' => []]]],
    ]));
});

/** @return array{vendor: Vendor, admin: User, member: User} */
function appCheckImageTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

function appCheckImageCheck(Vendor $vendor, array $overrides = []): Check
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ]);

    return Check::create(array_merge([
        'check_type' => 'Check',
        'check_number' => random_int(1000, 999999),
        'date' => now()->format('Y-m-d'),
        'bank_account_id' => $account->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'amount' => 100,
    ], $overrides));
}

it('needs a token to upload a check image', function () {
    $this->postJson('/api/app/v1/checks/1/images', [])->assertUnauthorized();
});

it('lets the owning admin attach a photo to their own check', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appCheckImageTeam();
    Sanctum::actingAs($admin, ['app']);

    $check = appCheckImageCheck($vendor);

    $response = $this->post("/api/app/v1/checks/{$check->id}/images", [
        'file' => UploadedFile::fake()->image('check.jpg'),
    ])->assertCreated();

    $image = CheckImage::where('check_id', $check->id)->sole();
    expect($image->match_status)->toBe(CheckImage::STATUS_MANUAL)
        ->and($response->json('data.images.0.id'))->toBe($image->id);
});

it('refuses a Member uploading a check image, like the web', function () {
    ['vendor' => $vendor, 'member' => $member] = appCheckImageTeam();
    Sanctum::actingAs($member, ['app']);

    $check = appCheckImageCheck($vendor);

    $this->post("/api/app/v1/checks/{$check->id}/images", [
        'file' => UploadedFile::fake()->image('check.jpg'),
    ])->assertForbidden();

    expect(CheckImage::where('check_id', $check->id)->exists())->toBeFalse();
});

it('refuses an admin whose company did not write the check', function () {
    ['admin' => $admin] = appCheckImageTeam();
    Sanctum::actingAs($admin, ['app']);

    $otherVendor = Vendor::factory()->create();
    $foreignCheck = appCheckImageCheck($otherVendor);

    $this->post("/api/app/v1/checks/{$foreignCheck->id}/images", [
        'file' => UploadedFile::fake()->image('check.jpg'),
    ])->assertForbidden();
});

it('rejects a missing file and an unsupported file type, like the web', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appCheckImageTeam();
    Sanctum::actingAs($admin, ['app']);

    $check = appCheckImageCheck($vendor);

    $this->postJson("/api/app/v1/checks/{$check->id}/images", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    $this->post(
        "/api/app/v1/checks/{$check->id}/images",
        ['file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ['Accept' => 'application/json'],
    )->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    expect(CheckImage::where('check_id', $check->id)->exists())->toBeFalse();
});

it('404s uploading to a check that does not exist', function () {
    ['admin' => $admin] = appCheckImageTeam();
    Sanctum::actingAs($admin, ['app']);

    $this->post('/api/app/v1/checks/999999/images', [
        'file' => UploadedFile::fake()->image('check.jpg'),
    ])->assertNotFound();
});
