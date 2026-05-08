<?php

use App\Jobs\LookupEwccvForVendor;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function makeWorkersCompDoc(array $overrides = []): VendorDoc
{
    $vendor = Vendor::factory()->create();

    return VendorDoc::create(array_merge([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'effective_date' => now()->subMonths(2)->toDateString(),
        'expiration_date' => now()->addMonths(10)->toDateString(),
        'number' => 'WC-TEST-1234',
        'doc_filename' => 'fake.pdf',
    ], $overrides));
}

it('dispatches EWCCV lookup job when a workers comp VendorDoc is created', function () {
    Bus::fake();

    $doc = makeWorkersCompDoc();

    Bus::assertDispatched(LookupEwccvForVendor::class, function (LookupEwccvForVendor $job) use ($doc) {
        return $job->vendorDocId === $doc->id;
    });
});

it('does not dispatch EWCCV lookup for non-workers VendorDocs', function () {
    Bus::fake();

    makeWorkersCompDoc(['type' => 'general']);

    Bus::assertNotDispatched(LookupEwccvForVendor::class);
});

it('does not dispatch EWCCV lookup for already-expired workers policies', function () {
    Bus::fake();

    makeWorkersCompDoc([
        'effective_date' => now()->subYears(2)->toDateString(),
        'expiration_date' => now()->subYear()->toDateString(),
    ]);

    Bus::assertNotDispatched(LookupEwccvForVendor::class);
});

it('re-dispatches EWCCV lookup when policy number changes', function () {
    $doc = makeWorkersCompDoc();

    Bus::fake();

    $doc->number = 'WC-TEST-NEW-9999';
    $doc->save();

    Bus::assertDispatched(LookupEwccvForVendor::class, function (LookupEwccvForVendor $job) use ($doc) {
        return $job->vendorDocId === $doc->id;
    });
});

it('does not re-dispatch when an unrelated field changes', function () {
    $doc = makeWorkersCompDoc();

    Bus::fake();

    $doc->doc_filename = 'updated.pdf';
    $doc->save();

    Bus::assertNotDispatched(LookupEwccvForVendor::class);
});

it('the lookup job no-ops when the VendorDoc has been deleted', function () {
    Bus::fake();

    $doc = makeWorkersCompDoc();
    $docId = $doc->id;
    $doc->forceDelete();

    // Should not throw — job exits cleanly when row is gone.
    (new LookupEwccvForVendor($docId))->handle();
})->throwsNoExceptions();

it('the lookup job no-ops when the VendorDoc is not a workers comp policy', function () {
    Bus::fake();

    $doc = makeWorkersCompDoc(['type' => 'general']);

    // Should not throw and should not invoke the artisan command path.
    (new LookupEwccvForVendor($doc->id))->handle();
})->throwsNoExceptions();
