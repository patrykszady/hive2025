<?php

use App\Models\VendorDoc;

/**
 * ewccv_summary drives the shield badge on /vendor_docs. It reads the state
 * that ewccv:scrape-tracking stamps into options['ewccv'].
 */
function makeDoc(array $attributes): VendorDoc
{
    return new VendorDoc(array_merge([
        'type' => 'workers',
        'number' => 'WC-1',
        'doc_filename' => 'x.pdf',
    ], $attributes));
}

it('summarizes a tracking policy', function () {
    $doc = makeDoc(['options' => ['ewccv' => ['status' => 'tracking', 'checked_at' => '2026-08-16T12:00:00Z']]]);

    expect($doc->ewccv_summary)
        ->tracking->toBeTrue()
        ->verified_at->toBe('08/16/2026')
        ->tip->toBe('EWCCV coverage tracking enabled · checked 08/16/2026');
});

it('summarizes a failed lookup with a readable reason', function () {
    $doc = makeDoc(['options' => ['ewccv' => ['status' => 'failed', 'reason' => 'no_matching_result']]]);

    expect($doc->ewccv_summary)
        ->tracking->toBeFalse()
        ->verified_at->toBeNull()
        ->tip->toBe('EWCCV lookup failed: no matching result');
});

it('is null for docs never looked up and for non-workers docs', function () {
    expect(makeDoc([])->ewccv_summary)->toBeNull()
        ->and(makeDoc(['options' => ['other' => true]])->ewccv_summary)->toBeNull()
        ->and(makeDoc(['type' => 'liability', 'options' => ['ewccv' => ['status' => 'tracking']]])->ewccv_summary)->toBeNull();
});
