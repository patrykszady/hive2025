<?php

use App\Livewire\VendorDocs\VendorDocsCard;
use App\Livewire\VendorDocs\VendorDocsIndex;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * /vendor_docs ran one `SELECT COUNT(DISTINCT type) ...` per vendor card,
 * just to size the loading skeleton (34 extra queries). These pin: the
 * count is computed once for every vendor in a single query, and the
 * skeleton uses it instead of running its own query when it's provided.
 */
function perf_vendorDocsUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Docs', 'last_name' => 'Admin',
        'email' => 'vdocs-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_vendorDocsSubVendor(User $user, string $name): Vendor
{
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => $name]);
    $user->vendor->vendors()->attach($sub->id);

    return $sub;
}

function perf_makeVendorDoc(User $user, Vendor $sub, string $type): VendorDoc
{
    // withoutEvents: a non-expired "workers" doc queues a real EWCCV lookup
    // job via VendorDocObserver (same reason tests/Feature/MoveVendorDocsTest
    // creates its fixtures this way).
    return VendorDoc::withoutEvents(fn () => VendorDoc::withoutGlobalScopes()->create([
        'type' => $type,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'doc_filename' => 'test.pdf',
        'effective_date' => now()->subYear(),
        'expiration_date' => now()->addYear(),
    ]));
}

it('computes distinct doc-type counts for every vendor in one query', function () {
    $user = perf_vendorDocsUser();
    $this->actingAs($user);

    $subA = perf_vendorDocsSubVendor($user, 'Sub A');
    perf_makeVendorDoc($user, $subA, 'general');
    perf_makeVendorDoc($user, $subA, 'workers');

    $subB = perf_vendorDocsSubVendor($user, 'Sub B');
    perf_makeVendorDoc($user, $subB, 'general');

    $index = new VendorDocsIndex();

    DB::enableQueryLog();
    $counts = $index->vendorDocTypeCounts();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($counts[$subA->id])->toBe(2)
        ->and($counts[$subB->id])->toBe(1);

    // vendorDocTypeCounts()'s own aggregate query — distinct from the
    // has()/with() queries $this->vendors() also runs against vendor_docs.
    $aggregateQuery = $queries->first(fn ($sql) => str_contains($sql, 'select "vendor_id", "type" from "vendor_docs"'));
    expect($aggregateQuery)->not->toBeNull();
    expect($queries->filter(fn ($sql) => $sql === $aggregateQuery))->toHaveCount(1);
});

it('skips its own COUNT query in the skeleton when the parent already provided one', function () {
    $user = perf_vendorDocsUser();
    $this->actingAs($user);

    $sub = perf_vendorDocsSubVendor($user, 'Sub A');
    perf_makeVendorDoc($user, $sub, 'general');
    perf_makeVendorDoc($user, $sub, 'workers');
    perf_makeVendorDoc($user, $sub, 'state_license');

    $card = new VendorDocsCard();

    DB::enableQueryLog();
    $withCount = $card->placeholder(['vendor' => $sub, 'docTypeCount' => 3]);
    $queriesWithCount = collect(DB::getQueryLog());
    DB::flushQueryLog();

    $withoutCount = $card->placeholder(['vendor' => $sub]);
    $queriesWithoutCount = collect(DB::getQueryLog());
    DB::disableQueryLog();

    // Both skeletons paint the same row count...
    expect($withCount->getData()['rows'])->toBe(3)
        ->and($withoutCount->getData()['rows'])->toBe(3);

    // ...but only the fallback path (no pre-computed count) hits the DB.
    expect($queriesWithCount)->toHaveCount(0)
        ->and($queriesWithoutCount)->toHaveCount(1);
});

it('caps the pre-loaded count at the skeleton row ceiling', function () {
    $card = new VendorDocsCard();

    $view = $card->placeholder(['vendor' => null, 'docTypeCount' => 999]);

    expect($view->getData()['rows'])->toBe(VendorDocsCard::placeholderRows());
});
