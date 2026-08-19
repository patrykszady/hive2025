<?php

use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * TrackMyVendor is webhook-only — no REST API and no API key, so everything
 * arrives as a signed push. These pin the delivery contract from their
 * integration docs: X-TMV-Signature (HMAC-SHA256 of the raw body) and
 * `{ event, created_at, data: { vendor_name, expiration_date, days_left } }`.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // QUEUE_CONNECTION=sync in phpunit.xml: creating a workers doc would
    // otherwise run VendorDocObserver's EWCCV lookup for real (browser + email).
    Queue::fake();

    config()->set('services.trackmyvendor.webhook_secret', 'tmv-secret');

    $this->vendor = Vendor::factory()->create(['business_name' => 'Morenos Drywall, Inc']);
    $this->doc = VendorDoc::create([
        'type' => 'workers',
        'vendor_id' => $this->vendor->id,
        'belongs_to_vendor_id' => 1,
        'number' => 'R2WC795013',
        'effective_date' => now()->subMonths(3),
        'expiration_date' => now()->addYear(),
        'doc_filename' => 'coi.pdf',
    ]);
});

function tmvPost(array $payload, ?string $secret = 'tmv-secret', bool $prefixed = false)
{
    $body = json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $sig = hash_hmac('sha256', $body, $secret);
        $headers['HTTP_X-TMV-Signature'] = $prefixed ? "sha256={$sig}" : $sig;
    }

    return test()->call('POST', '/webhooks/trackmyvendor', [], [], [], $headers, $body);
}

it('records a compliance failure and clears the verified date', function () {
    tmvPost([
        'event' => 'contractor.compliance_changed',
        'created_at' => now()->toIso8601String(),
        // Their payload spells compliance as pass/fail.
        'data' => ['vendor_name' => 'MORENOS DRYWALL INC', 'status' => 'fail'],
    ])->assertOk();

    $summary = $this->doc->refresh()->trackmyvendor_summary;

    expect($summary['compliant'])->toBeFalse()
        ->and($summary['verified_at'])->toBeNull()
        ->and($summary['tip'])->toContain('NOT compliant');
});

it('records a passing compliance change as verified', function () {
    tmvPost([
        'event' => 'contractor.compliance_changed',
        'data' => ['vendor_name' => 'Morenos Drywall, Inc', 'status' => 'pass'],
    ])->assertOk();

    $summary = $this->doc->refresh()->trackmyvendor_summary;

    expect($summary['compliant'])->toBeTrue()
        ->and($summary['verified_at'])->toBe(now()->format('m/d/Y'));
});

it('treats an expiry warning as still-covered but surfaces the countdown', function () {
    tmvPost([
        'event' => 'contractor.coi_expiring',
        'data' => ['vendor_name' => 'MORENOS DRYWALL INC', 'days_left' => 30, 'expiration_date' => '2026-09-16'],
    ])->assertOk();

    $summary = $this->doc->refresh()->trackmyvendor_summary;

    // Expiring is a warning, not a lapse — coverage is valid today.
    expect($summary['compliant'])->toBeTrue()
        ->and($summary['tip'])->toContain('expires in 30 days');
});

it('accepts a sha256-prefixed signature', function () {
    tmvPost(
        ['event' => 'contractor.compliance_changed', 'data' => ['vendor_name' => 'MORENOS DRYWALL INC', 'status' => 'pass']],
        'tmv-secret',
        prefixed: true
    )->assertOk();

    expect($this->doc->refresh()->trackmyvendor_summary['compliant'])->toBeTrue();
});

it('rejects a delivery signed with the wrong secret', function () {
    tmvPost(
        ['event' => 'contractor.compliance_changed', 'data' => ['vendor_name' => 'MORENOS DRYWALL INC', 'status' => 'fail']],
        'wrong-secret'
    )->assertStatus(401);

    expect($this->doc->refresh()->options['trackmyvendor'] ?? null)->toBeNull();
});

it('rejects an unsigned delivery when a secret is configured', function () {
    tmvPost(
        ['event' => 'contractor.compliance_changed', 'data' => ['vendor_name' => 'MORENOS DRYWALL INC', 'status' => 'fail']],
        secret: null
    )->assertStatus(401);
});

it('ignores non-insurance events without touching coverage state', function () {
    tmvPost([
        'event' => 'contractor.w9_missing',
        'data' => ['vendor_name' => 'MORENOS DRYWALL INC'],
    ])->assertOk()->assertJson(['status' => 'ignored']);

    expect($this->doc->refresh()->options['trackmyvendor'] ?? null)->toBeNull();
});

it('reports cleanly when the vendor name matches nothing', function () {
    tmvPost([
        'event' => 'contractor.compliance_changed',
        'data' => ['vendor_name' => 'Someone Else Entirely LLC', 'status' => 'fail'],
    ])->assertOk()->assertJson(['status' => 'no_vendor_matched']);
});

it('shows in the Verified column alongside the other providers', function () {
    tmvPost([
        'event' => 'contractor.compliance_changed',
        'data' => ['vendor_name' => 'MORENOS DRYWALL INC', 'status' => 'pass'],
    ])->assertOk();

    $summary = $this->doc->refresh()->verification_summary;

    expect($summary['verified'])->toBeTrue()
        ->and($summary['sources'])->toContain('trackmyvendor')
        ->and($summary['verified_at'])->toBe(now()->format('m/d/Y'));
});
