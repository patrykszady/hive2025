<?php

use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('re-files documents under another vendor and clears the workers-comp stamp, only with --apply', function () {
    $fx = kotVendors();

    // Created quietly: the observer's own lookup dispatch would hold the
    // job's unique lock and swallow the one the move queues.
    [$general, $workers] = VendorDoc::withoutEvents(fn () => [
        VendorDoc::withoutGlobalScopes()->create([
            'type' => 'general', 'vendor_id' => $fx['old']->id, 'belongs_to_vendor_id' => $fx['gs']->id, 'doc_filename' => 'test.pdf',
            'number' => 'U25AC166671-01', 'effective_date' => '2026-06-16', 'expiration_date' => '2027-06-16',
        ]),
        VendorDoc::withoutGlobalScopes()->create([
            'type' => 'workers', 'vendor_id' => $fx['old']->id, 'belongs_to_vendor_id' => $fx['gs']->id, 'doc_filename' => 'test.pdf',
            'number' => 'WCIL000171300', 'effective_date' => '2026-07-21', 'expiration_date' => '2027-07-21',
            'options' => ['ewccv' => ['status' => 'failed', 'reason' => 'recaptcha_failed_ui']],
        ]),
    ]);

    $this->artisan('vendor-docs:move', ['--doc' => [$general->id, $workers->id], '--to-vendor' => $fx['new']->id])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect($general->fresh()->vendor_id)->toBe($fx['old']->id)
        ->and($workers->fresh()->options['ewccv']['status'])->toBe('failed');

    // A dry run queues no lookup (Scout's indexing job is unrelated).
    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\LookupEwccvForVendor::class);

    $this->artisan('vendor-docs:move', ['--doc' => [$general->id, $workers->id], '--to-vendor' => $fx['new']->id, '--apply' => true])
        ->expectsOutputToContain('workers-comp tracking stamp cleared | workers-comp lookup queued')
        ->assertSuccessful();

    // The move queues the state lookup under the right vendor — for the
    // workers policy only.
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\LookupEwccvForVendor::class, 1);

    expect($general->fresh()->vendor_id)->toBe($fx['new']->id)
        ->and($workers->fresh()->vendor_id)->toBe($fx['new']->id)
        ->and($workers->fresh()->options)->toBeNull()
        ->and($general->fresh()->belongs_to_vendor_id)->toBe($fx['gs']->id);
});

it('refuses an unknown document or vendor', function () {
    $fx = kotVendors();

    $this->artisan('vendor-docs:move', ['--doc' => [999], '--to-vendor' => $fx['new']->id])->assertFailed();
    $this->artisan('vendor-docs:move', ['--doc' => [1], '--to-vendor' => 999])->assertFailed();
});
