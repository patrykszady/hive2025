<?php

use App\Models\EwccvCoverageCheck;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

// =========================================================================
// EWCCV lists a renewal only once the insurer reports it, weeks after it
// starts. When the certificate on file is not found, the one it renewed is
// verified instead, as of its last day (Patryk, 2026-10-14, the M Power
// renewal: the 2026-27 certificate was not on EWCCV yet).
// =========================================================================

/**
 * @return array{0: VendorDoc, 1: VendorDoc} the previous certificate and its renewal
 */
function renewedCertificates(Vendor $vendor): array
{
    $make = fn (string $from, string $to) => VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => '6-JUB-A421140-4-25',
        'doc_filename' => "wc-{$from}.pdf", 'effective_date' => $from, 'expiration_date' => $to,
    ]));

    return [$make('2025-10-08', '2026-10-08'), $make('2026-10-08', '2027-10-08')];
}

function scraperFindsNothingFor(Vendor ...$vendors): void
{
    Process::fake(fn () => Process::result(json_encode([
        'success' => [],
        'failed' => array_map(fn (Vendor $v) => [
            'vendorId' => $v->id, 'businessName' => $v->business_name, 'reason' => 'no_match',
            'searched' => [['type' => 'employer', 'term' => 'Power Solution', 'count' => 5]],
            'candidates' => [['employerName' => 'POWER SOLUTION SERVICES INC', 'policyNumber' => '83WECIN4849', 'score' => 1, 'policyScore' => 0.21]],
        ], $vendors),
        'skipped' => [],
    ])));
}

beforeEach(function () {
    $this->travelTo('2026-10-14 18:00:00');
});

it('finds the certificate a renewal replaced: the latest that ended by the day it started', function () {
    $vendor = Vendor::factory()->create();
    [$previous, $renewal] = renewedCertificates($vendor);
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => 'OLDER',
        'doc_filename' => 'older.pdf', 'effective_date' => '2023-05-17', 'expiration_date' => '2024-05-07',
    ]));

    expect($renewal->previousWorkersDoc()?->id)->toBe($previous->id)
        ->and($previous->previousWorkersDoc())->toBeNull();
});

it('queues a check of the previous certificate as of its last day when EWCCV does not list the renewal, and says so on the row', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'M Power Solution Company']);
    [$previous, $renewal] = renewedCertificates($vendor);
    scraperFindsNothingFor($vendor);

    $this->artisan('ewccv:scrape-tracking', [
        '--belongs-to-vendor-id' => 1, '--vendor-id' => [$vendor->id], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test',
    ])
        ->expectsOutputToContain('Queued a check of the previous policy 6-JUB-A421140-4-25 as of 10/07/2026')
        ->assertExitCode(0);

    $check = EwccvCoverageCheck::where('vendor_id', $vendor->id)->sole();
    expect($check->status)->toBe('pending')
        ->and($check->coverage_date->toDateString())->toBe('2026-10-07')
        ->and($renewal->fresh()->options['ewccv'])->toMatchArray(['status' => 'failed', 'reason' => 'no_match'])
        ->and($renewal->fresh()->ewccv_summary['tip'])->toContain('the previous policy (6-JUB-A421140-4-25) is queued for a check as of its last day');

    $check->update(['status' => 'covered', 'policy_number' => '6JUBA421140425', 'employer_name' => 'M POWER SOLUTION CO', 'checked_at' => now()]);

    expect($renewal->fresh()->ewccv_summary['tip'])->toContain('EWCCV may not list this renewal yet; the previous policy (6-JUB-A421140-4-25) was covered on 10/07/2026')
        ->and($previous->fresh()->ewccv_summary['tip'])->toContain('covered on 10/07/2026');
});

it('asks nothing new when the previous certificate is already verified', function () {
    $vendor = Vendor::factory()->create();
    renewedCertificates($vendor);
    EwccvCoverageCheck::factory()->covered()->create(['vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'coverage_date' => '2026-10-07']);
    scraperFindsNothingFor($vendor);

    $this->artisan('ewccv:scrape-tracking', [
        '--belongs-to-vendor-id' => 1, '--vendor-id' => [$vendor->id], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test',
    ])->assertExitCode(0);

    expect(EwccvCoverageCheck::where('vendor_id', $vendor->id)->count())->toBe(1);
});

it('shows how alike a near-identical policy number EWCCV matched is', function () {
    $vendor = Vendor::factory()->create();
    [, $renewal] = renewedCertificates($vendor);
    $renewal->options = ['ewccv' => [
        'status' => 'tracking', 'match_type' => 'policyNumber', 'matched_name' => 'M POWER SOLUTION CO',
        'matched_policy' => '6JUBA42114045', 'policy_score' => 0.93, 'checked_at' => now()->toIso8601String(),
    ]];
    $renewal->saveQuietly();

    expect($renewal->fresh()->ewccv_summary['tip'])->toContain("EWCCV lists policy 6JUBA42114045, 93% alike the certificate's 6-JUB-A421140-4-25")
        ->not->toContain('matched by name');
});
