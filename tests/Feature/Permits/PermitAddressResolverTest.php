<?php

use App\Http\Controllers\ExpenseAutoMatchController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\PermitLookup;
use App\Models\Vendor;
use App\Services\Permits\PermitAddressResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// Expense 29033 (2026-11-05): $225 to the Village of Palatine, receipt
// "Permit: BLDR-026546-2026 | Invoice: INV-00044895", no address — and the
// matcher had nothing to go on. The village's own permit portal knows the
// permit was issued for 116 N Benton St, which is project 347.
// =========================================================================

/** The portal's global-search answer for one permit, the way Tyler EnerGov returns it. */
function palatineAnswer(array $results): array
{
    return ['Success' => true, 'Result' => ['EntityResults' => $results, 'TotalFound' => count($results)]];
}

function bentonPermit(): array
{
    return [
        'CaseId' => '3188cb1f-2a03-488d-bed5-6c73460dbb32',
        'CaseNumber' => 'BLDR-026546-2026',
        'CaseType' => "Building Permit for Single Family Alteration  \r\n",
        'CaseStatus' => 'Complete',
        'Description' => 'Replace water main lead pipe.',
        'Address' => ['AddressLine1' => '116', 'PreDirection' => 'N', 'AddressLine2' => 'BENTON', 'StreetTypeName' => 'ST', 'PostDirection' => '', 'UnitOrSuite' => '', 'City' => 'Palatine', 'StateName' => 'IL', 'PostalCode' => '60067', 'FullAddress' => '116 N BENTON ST Palatine IL 60067'],
        'AddressDisplay' => '116 N BENTON ST Palatine IL 60067',
        'ModuleName' => 2,
    ];
}

it('reads the permit numbers off a village receipt', function () {
    expect(PermitAddressResolver::permitNumbers("#: TYLX141575246\n\nPermit: BLDR-026546-2026 | Invoice: INV-00044895\n\$225.00"))
        ->toBe(['BLDR-026546-2026'])
        ->and(PermitAddressResolver::permitNumbers('Permit BLDR-026546-2026 and ELEC-001234-2026, BLDR-026546-2026 again'))
        ->toBe(['BLDR-026546-2026', 'ELEC-001234-2026'])
        ->and(PermitAddressResolver::permitNumbers('Invoice INV-00044895 only'))
        ->toBe([]);
});

it('knows which portal a village vendor is, and that a supplier is none', function () {
    expect(PermitAddressResolver::portalForVendor('Village of Palatine')['key'] ?? null)->toBe('palatine')
        ->and(PermitAddressResolver::portalForVendor('VILLAGE OF PALATINE - BUILDING')['key'] ?? null)->toBe('palatine')
        ->and(PermitAddressResolver::portalForVendor('Menards'))->toBeNull()
        ->and(PermitAddressResolver::portalForVendor(null))->toBeNull();
});

it('asks the village portal which house a permit is for, and remembers the answer', function () {
    Http::fake(['permits.palatine.il.us/*' => Http::response(palatineAnswer([bentonPermit()]))]);

    $lookup = app(PermitAddressResolver::class)->resolve('Village of Palatine', 'BLDR-026546-2026');

    expect($lookup)->not->toBeNull()
        ->and($lookup->found)->toBeTrue()
        ->and($lookup->address)->toBe('116 N Benton St')
        ->and($lookup->city)->toBe('Palatine')
        ->and($lookup->state)->toBe('IL')
        ->and($lookup->zip)->toBe('60067')
        ->and($lookup->status)->toBe('Complete')
        ->and($lookup->case_type)->toBe('Building Permit for Single Family Alteration')
        ->and($lookup->description)->toBe('Replace water main lead pipe.')
        ->and($lookup->portal)->toBe('palatine');

    // The portal's own front end sends these tenant headers and its full
    // search model; anything slimmer gets a 500.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/EnerGov_Prod/selfservice/api/energov/search/search')
        && $request->hasHeader('tenantname', 'PalatineILProd')
        && $request->hasHeader('tyler-tenanturl', 'PalatineILProd')
        && $request->hasHeader('tenantid', '1')
        && $request['Keyword'] === 'BLDR-026546-2026'
        && $request['ExactMatch'] === true
        && is_array($request['PermitCriteria'])
        && is_array($request['PermitSortList']));

    // Asked once: the second time comes from the table.
    $again = app(PermitAddressResolver::class)->resolve('Village of Palatine', 'bldr-026546-2026');
    expect($again->id)->toBe($lookup->id);
    Http::assertSentCount(1);
});

it('asks nothing for a vendor that is not a village it can ask', function () {
    Http::fake();

    expect(app(PermitAddressResolver::class)->resolve('Menards', 'BLDR-026546-2026'))->toBeNull();
    Http::assertNothingSent();
    expect(PermitLookup::count())->toBe(0);
});

it('remembers a permit the portal does not know, and asks again after a week', function () {
    Http::fake(['permits.palatine.il.us/*' => Http::response(palatineAnswer([]))]);

    $miss = app(PermitAddressResolver::class)->resolve('Village of Palatine', 'BLDR-000001-2026');
    expect($miss->found)->toBeFalse()->and($miss->address)->toBeNull();

    app(PermitAddressResolver::class)->resolve('Village of Palatine', 'BLDR-000001-2026');
    Http::assertSentCount(1);

    $miss->forceFill(['looked_up_at' => now()->subDays(8)])->save();
    app(PermitAddressResolver::class)->resolve('Village of Palatine', 'BLDR-000001-2026');
    Http::assertSentCount(2);
});

it('keeps nothing when the portal is down, so the next run asks again', function () {
    Http::fake(['permits.palatine.il.us/*' => Http::response(['Message' => 'An error has occurred.'], 500)]);

    expect(app(PermitAddressResolver::class)->resolve('Village of Palatine', 'BLDR-026546-2026'))->toBeNull()
        ->and(PermitLookup::count())->toBe(0);
});

it('gives the expense matcher the permit\'s house as a candidate, like an address on the receipt', function () {
    Http::fake(['permits.palatine.il.us/*' => Http::response(palatineAnswer([bentonPermit()]))]);
    $village = Vendor::factory()->create(['business_name' => 'Village of Palatine']);

    $controller = new class extends ExpenseAutoMatchController
    {
        /** @return array<int, string> */
        public function extractCandidates(Expense $expense): array
        {
            return $this->extractPurchaseOrderCandidates($expense);
        }

        public function match(string $po, string $date, array $candidates): ?array
        {
            return $this->matchPurchaseOrderToProjectAtDate($po, $date, $candidates);
        }
    };

    $expense = new Expense(['vendor_id' => $village->id]);
    $expense->setRelation('receipts', new Collection([new ExpenseReceipts(['receipt_items' => [
        'purchase_order' => '',
        'handwritten_notes' => [],
        'raw_content' => "#: TYLX141575246\n\nNovember 05, 2026 09:33 AM CST\n\nPermit: BLDR-026546-2026 | Invoice: INV-00044895\n\$225.00\n\nTotal\t\$225.00",
    ]])]));

    $candidates = $controller->extractCandidates($expense);
    expect($candidates)->toContain('116 N Benton St');

    // …and that candidate lands on the Benton job the way a written address would.
    $norm = fn (string $v) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', strtolower($v))));
    $benton = [
        'id' => 347, 'created_at' => 0,
        'statuses' => [['code' => 4, 'start_date' => '2026-08-01'], ['code' => 6, 'start_date' => '2026-09-15']],
        'variants' => [$norm('116 N Benton St'), $norm('116 N Benton St Bathroom'), $norm('Bathroom')],
        'address_variants' => [$norm('116 N Benton St'), $norm('116 N Benton St Bathroom')],
        'city' => 'palatine',
    ];
    $other = [
        'id' => 364, 'created_at' => 0,
        'statuses' => [['code' => 6, 'start_date' => '2026-06-01']],
        'variants' => [$norm('3154 Violet Ln'), $norm('3154 Violet Ln Home Renovation'), $norm('Home Renovation')],
        'address_variants' => [$norm('3154 Violet Ln'), $norm('3154 Violet Ln Home Renovation')],
        'city' => 'northbrook',
    ];

    expect($controller->match($candidates[0], '2026-11-05', [$other, $benton]))->toMatchArray(['project_id' => 347, 'ambiguous' => false]);
});

it('asks no portal for an ordinary supplier\'s receipt', function () {
    Http::fake();
    $menards = Vendor::factory()->create(['business_name' => 'Menards']);

    $controller = new class extends ExpenseAutoMatchController
    {
        /** @return array<int, string> */
        public function extractCandidates(Expense $expense): array
        {
            return $this->extractPurchaseOrderCandidates($expense);
        }
    };

    $expense = new Expense(['vendor_id' => $menards->id]);
    $expense->setRelation('receipts', new Collection([new ExpenseReceipts(['receipt_items' => ['purchase_order' => '', 'raw_content' => 'Permit: BLDR-026546-2026 on a lumber receipt']])]));

    expect($controller->extractCandidates($expense))->toBe([]);
    Http::assertNothingSent();
});
