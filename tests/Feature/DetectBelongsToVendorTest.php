<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\User;
use App\Models\UserVendor;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| detectBillToVendorId — OCR-based detection
|--------------------------------------------------------------------------
*/

function makeController(): CompanyEmailController
{
    return new CompanyEmailController(app(\App\Services\NylasService::class));
}

function callProtected(object $object, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($object, $method);

    return $ref->invoke($object, ...$args);
}

function fakeVendors(array $vendors): void
{
    Vendor::query()->withoutGlobalScopes()->delete();

    $now = now();

    foreach ($vendors as $vendor) {
        DB::table('vendors')->insert(array_merge([
            'business_type' => 'Subcontractor',
            'created_at' => $now,
            'updated_at' => $now,
        ], $vendor));
    }
}

it('detects vendor from BILL TO label', function () {
    fakeVendors([['id' => 60, 'business_name' => 'J Peterson Design, Inc.']]);

    $content = "SOME HEADER\n\nBILL TO:\nJ. PETERSON DESIGN\n317 WESTMINSTER\nPALATINE, IL 60067";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, null]))->toBe(60);
});

it('detects vendor from QUOTE TO label', function () {
    fakeVendors([['id' => 60, 'business_name' => 'J Peterson Design, Inc.']]);

    $content = "STUDIO 41\n\nQUOTE TO:\nJ PETERSON DESIGN\n317 WESTMINSTER DRIVE\nPALATINE, IL 60067";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, null]))->toBe(60);
});

it('detects vendor from SOLD TO label', function () {
    fakeVendors([['id' => 10, 'business_name' => 'Acme Corp']]);

    $content = "INVOICE\n\nSOLD TO:\nAcme Corp\n123 Main St";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, null]))->toBe(10);
});

it('detects vendor from SHIP TO label', function () {
    fakeVendors([['id' => 10, 'business_name' => 'Acme Corp']]);

    $content = "ORDER\n\nSHIP TO:\nAcme Corp\n456 Oak Ave";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, null]))->toBe(10);
});

it('detects vendor from ORDER TO label', function () {
    fakeVendors([['id' => 10, 'business_name' => 'Acme Corp']]);

    $content = "DOCUMENT\n\nORDER TO:\nAcme Corp\n789 Elm Blvd";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, null]))->toBe(10);
});

it('excludes the selling vendor from bill-to detection', function () {
    fakeVendors([
        ['id' => 40, 'business_name' => 'Studio 41'],
        ['id' => 60, 'business_name' => 'J Peterson Design, Inc.'],
    ]);

    $content = "QUOTE TO:\nStudio 41\n1410 NW HWY";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, 40]))->toBeNull();
});

it('returns null when no matching label found in OCR content', function () {
    $content = "CUSTOMER NAME:\nSome Random Company\n123 Main St";

    expect(callProtected(makeController(), 'detectBillToVendorId', [$content, null]))->toBeNull();
});

it('returns null for empty OCR content', function () {
    expect(callProtected(makeController(), 'detectBillToVendorId', ['', null]))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| detectBelongsToVendorFromEmail — email-based detection
|--------------------------------------------------------------------------
*/

it('resolves forwarding email to vendor via user_vendor', function () {
    $user = User::query()->create([
        'first_name' => 'Jenn',
        'last_name' => 'Peterson',
        'email' => 'jenn@jpeterson-design.com',
        'cell_phone' => '2245551000',
    ]);

    UserVendor::query()->create([
        'user_id' => $user->id,
        'vendor_id' => 60,
    ]);

    expect(callProtected(makeController(), 'detectBelongsToVendorFromEmail', ['jenn@jpeterson-design.com', null]))->toBe(60);
});

it('returns null for unknown email address', function () {
    expect(callProtected(makeController(), 'detectBelongsToVendorFromEmail', ['nobody@nowhere.com', null]))->toBeNull();
});

it('returns null for invalid email', function () {
    expect(callProtected(makeController(), 'detectBelongsToVendorFromEmail', ['not-an-email', null]))->toBeNull();
});

it('returns null for empty email', function () {
    expect(callProtected(makeController(), 'detectBelongsToVendorFromEmail', ['', null]))->toBeNull();
});
