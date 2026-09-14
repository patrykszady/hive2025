<?php

use App\Http\Controllers\VendorDocsController;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reconcile(?int $provided, ?int $calculated, string $insured): ?int
{
    $method = new \ReflectionMethod(VendorDocsController::class, 'reconcileVendor');
    $method->setAccessible(true);

    return $method->invoke(app(VendorDocsController::class), $provided, $calculated, $insured, 'test.pdf');
}

it('files the certificate under the vendor the message named when the same people own both records', function () {
    $fx = kotVendors();

    // The insured-name match landed on the old row; the request was for the new one.
    expect(reconcile($fx['new']->id, $fx['old']->id, 'Kot Construction'))->toBe($fx['new']->id);
});

it('trusts the message when the insured name resembles that vendor', function () {
    $mine = Vendor::factory()->create(['business_name' => 'Olszewski Hardwood Flooring', 'business_type' => 'Sub']);
    $other = Vendor::factory()->create(['business_name' => 'Olszewski Quality Flooring', 'business_type' => 'Sub']);

    expect(reconcile($mine->id, $other->id, 'Olszewski Hardwood Flooring Inc'))->toBe($mine->id);
});

it('keeps the insured name when the certificate is for someone else entirely', function () {
    $named = Vendor::factory()->create(['business_name' => 'Vol Nat Heating Inc', 'business_type' => 'Sub']);
    $insured = Vendor::factory()->create(['business_name' => 'Acme Roofing LLC', 'business_type' => 'Sub']);

    // An agent answered the heating request with a roofer's certificate.
    expect(reconcile($named->id, $insured->id, 'Acme Roofing LLC'))->toBe($insured->id);
});

it('uses whichever side knows when the other does not', function () {
    $fx = kotVendors();

    expect(reconcile(null, $fx['old']->id, 'Kot Construction'))->toBe($fx['old']->id)
        ->and(reconcile($fx['new']->id, null, 'Kot Construction'))->toBe($fx['new']->id)
        ->and(reconcile(null, null, 'Kot Construction'))->toBeNull();
});

it('recognises a renewal of a policy already on file', function () {
    $fx = kotVendors();

    VendorDoc::withoutGlobalScopes()->create([
        'type' => 'general', 'vendor_id' => $fx['new']->id, 'belongs_to_vendor_id' => $fx['gs']->id, 'doc_filename' => 'test.pdf',
        'number' => 'U25AC166671-00', 'effective_date' => '2025-06-16', 'expiration_date' => '2026-06-16',
    ]);

    $info = [
        'insured_name' => ['valueString' => 'Kot Construction', 'confidence' => 0.9],
        'general_multi' => ['valueArray' => [[
            'valueObject' => ['general_policy_number' => ['valueString' => 'U25AC 166671-01']],
        ]]],
    ];

    $method = new \ReflectionMethod(VendorDocsController::class, 'policyContinuityVendor');
    $method->setAccessible(true);

    expect($method->invoke(app(VendorDocsController::class), $info))->toBe($fx['new']->id)
        ->and($method->invoke(app(VendorDocsController::class), ['insured_name' => ['valueString' => 'X']]))->toBeNull();
});
