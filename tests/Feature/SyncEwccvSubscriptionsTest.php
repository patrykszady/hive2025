<?php

use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

// The daily sync reads what EWCCV tracks for the account and keeps the docs
// honest both ways: a listed policy is stamped tracking (even when EWCCV
// spells the number with a prefix), and a doc stamped tracking that EWCCV no
// longer lists goes back to pending so a browser session re-tracks it.

function syncDoc(int $belongsTo, string $number, ?array $ewccv): VendorDoc
{
    $vendor = Vendor::factory()->create();

    return VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => $belongsTo, 'number' => $number,
        'doc_filename' => 'x.pdf', 'effective_date' => now()->subMonths(3)->toDateString(), 'expiration_date' => now()->addMonths(9)->toDateString(),
        'options' => $ewccv ? ['ewccv' => $ewccv] : [],
    ]));
}

it('stamps what EWCCV lists and resets what it no longer lists', function () {
    $dir = sys_get_temp_dir().'/ewccv-sync-'.uniqid();
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/.last_login_url.json', json_encode(['url' => 'https://www.ewccv.com/cvs/login/?t=test', 'cached_at' => now()->toIso8601String()]));

    $listed = syncDoc(1, '100134391', null);
    $gone = syncDoc(1, 'WC5-39S-702746-026', ['status' => 'tracking', 'checked_at' => now()->subDay()->toIso8601String()]);
    $neverTracked = syncDoc(1, 'R2WC728403', ['status' => 'failed', 'reason' => 'no_results']);

    Process::fake(['*' => Process::result(json_encode(['ok' => true, 'user' => ['userId' => 1], 'subscriptions' => [
        ['policySubscriptionId' => 9, 'writtenPolicyId' => 77, 'policyNumber' => 'AFWCP100134391', 'stateCode' => '12', 'createDate' => '2026-10-12T22:26:00.000Z', 'employerInformation' => ['employer' => 'SERENOS']],
    ]]))]);

    $this->artisan('ewccv:sync-subscriptions', ['--belongs-to-vendor-id' => 1, '--output-dir' => $dir])
        ->expectsOutputToContain('Stamped 1 vendor doc(s) as EWCCV-tracked.')
        ->expectsOutputToContain('Reset to pending (tracked here, not at EWCCV): 1')
        ->assertExitCode(0);

    expect($listed->fresh()->options['ewccv']['status'])->toBe('tracking')
        ->and($listed->fresh()->options['ewccv']['written_policy_id'])->toBe(77)
        ->and($gone->fresh()->options['ewccv'])->toMatchArray(['status' => 'pending', 'reason' => 'not_tracked_by_ewccv'])
        ->and($gone->fresh()->ewccv_summary['tip'])->toContain('no longer lists')
        ->and($neverTracked->fresh()->options['ewccv']['status'])->toBe('failed');
});
