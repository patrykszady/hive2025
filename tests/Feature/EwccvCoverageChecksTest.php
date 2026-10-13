<?php

use App\Jobs\RunPendingEwccvLookups;
use App\Livewire\VendorDocs\AuditShow;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\EwccvCoverageCheck;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\ScrapeLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// Workers comp audit, 2026-10-12: every policy on the audit shows whether
// and when its coverage was verified, and the audit asks EWCCV whether each
// paid sub was covered on the audit's END date (ewccv:scrape-tracking
// --verify-only --coverage-date), which today's tracking state cannot say.
// =========================================================================

function auditFixture(): array
{
    $gc = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Audit', 'last_name' => 'Admin',
        'email' => 'audit-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $gc->id,
    ]);
    $user->vendors()->attach($gc->id, ['role_id' => 1]);
    test()->actingAs($user);

    $bank = BankAccount::create(['vendor_id' => $gc->id, 'bank_id' => 1, 'account_number' => '1234', 'plaid_account_id' => 'plaid-'.uniqid(), 'type' => 'checking']);
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Kot Construction', 'state' => 'IL', 'address' => '12 Main St', 'category_id' => null]);
    // The company's vendor list (vendors_vendor) is what the VendorScope shows.
    $gc->vendors()->attach($sub->id);
    Check::create(['check_type' => 'Check', 'check_number' => '1001', 'date' => '2025-06-15', 'bank_account_id' => $bank->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $user->id, 'amount' => 500]);

    $doc = VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'number' => 'WC-KOT-1',
        'doc_filename' => 'kot.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => now()->addMonths(3)->toDateString(),
        'options' => ['ewccv' => ['status' => 'tracking', 'checked_at' => '2026-10-12T20:00:00+00:00']],
    ]));

    return compact('gc', 'user', 'bank', 'sub', 'doc');
}

/** A workers comp doc for tenant 1, as the tracking lookups see it. */
function coverageWcDoc(?array $ewccv = null): VendorDoc
{
    $vendor = Vendor::factory()->create();

    return VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => 'WC-'.$vendor->id,
        'doc_filename' => 'wc-'.$vendor->id.'.pdf', 'effective_date' => now()->subMonths(6)->toDateString(),
        'expiration_date' => now()->addMonths(6)->toDateString(), 'options' => $ewccv ? ['ewccv' => $ewccv] : [],
    ]));
}

function coverageBridgedSession(): void
{
    Storage::disk('local')->put(BridgedSession::FILE, json_encode(['access_token' => str_repeat('t', 120), 'received_at' => now()->toIso8601String()]));
}

function auditPage(array $f, string $end = '2025-12-31')
{
    return Livewire::test(AuditShow::class, ['start_date' => '2025-01-01', 'end_date' => $end, 'audit_type' => 'workers', 'bank_account_ids' => [$f['bank']->id]]);
}

beforeEach(function () {
    Storage::fake('local');
    config(['services.ewccv.bypass_key' => null, 'services.ewccv.belongs_to_vendor_id' => 1, 'services.ewccv.lock_wait_seconds' => 0]);
});

it('shows on every policy of the audit whether and when its coverage was verified', function () {
    $f = auditFixture();
    $unverified = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Plain Painting']);
    $f['gc']->vendors()->attach($unverified->id);
    Check::create(['check_type' => 'Check', 'check_number' => '1002', 'date' => '2025-07-01', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $unverified->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 200]);
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $unverified->id, 'belongs_to_vendor_id' => $f['gc']->id, 'number' => 'WC-PLAIN',
        'doc_filename' => 'plain.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => '2025-12-31',
    ]));

    // The expired policy is shown (not verified) but is not an EWCCV check:
    // only policies active today are verified.
    auditPage($f)
        ->assertSee('WC verified 10/12/2026')
        ->assertSee('not verified')
        ->assertSee('EWCCV coverage as of 12/31/2025')
        ->assertSee('not checked yet (1 subs)');

    expect(AuditShow::verificationCell($f['doc']))->toBe('WC verified 10/12/2026 (EWCCV)');
});

it('asks EWCCV for coverage as of the end date for every paid sub, and shows the answers as they land', function () {
    Queue::fake();
    $f = auditFixture();

    // No browser session: the checks wait, the page says so.
    auditPage($f)
        ->call('verify_coverage_on_ewccv')
        ->assertSee('EWCCV: waiting')
        ->assertSee('Waiting for a browser session')
        ->assertSee('1 waiting');
    Queue::assertNotPushed(RunPendingEwccvLookups::class);

    $check = EwccvCoverageCheck::where('vendor_id', $f['sub']->id)->first();
    expect($check)->not->toBeNull()
        ->and($check->belongs_to_vendor_id)->toBe($f['gc']->id)
        ->and($check->coverage_date->toDateString())->toBe('2025-12-31')
        ->and($check->status)->toBe('pending');

    // Asking again changes nothing; with a session at hand the run starts now.
    expect(EwccvCoverageCheck::request($f['sub']->id, $f['gc']->id, '2025-12-31'))->toBeFalse();
    $check->update(['status' => 'pending', 'reason' => 'session_expired']);
    coverageBridgedSession();
    auditPage($f)->call('verify_coverage_on_ewccv');
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);
    expect($check->fresh()->reason)->toBeNull();

    // The answers.
    $check->update(['status' => 'covered', 'policy_number' => 'WC-KOT-1', 'employer_name' => 'KOT CONSTRUCTION INC', 'checked_at' => now()]);
    auditPage($f)->assertSee('EWCCV: covered')->assertSee('Covered on 12/31/2025: KOT CONSTRUCTION INC, policy WC-KOT-1')->assertSee('1 covered');

    $check->update(['status' => 'not_found', 'reason' => 'no_match']);
    auditPage($f)->assertSee('EWCCV: not found')->assertSee('Not found on EWCCV for 12/31/2025 (no match)')->assertSee('1 not found');

    // A different end date is a different question.
    expect(auditPage($f, '2025-06-30')->get('coverage_check_counts')['unchecked'])->toBe(1);
});

it('leaves the company itself, employees, retail and categorised vendors, and subs without a policy active today out of the coverage checks', function () {
    $f = auditFixture();
    $retail = Vendor::factory()->create(['business_type' => 'Retail']);
    $f['gc']->vendors()->attach($retail->id);
    Check::create(['check_type' => 'Check', 'check_number' => '1003', 'date' => '2025-03-01', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $retail->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 50]);
    $noDoc = Vendor::factory()->create(['business_type' => 'Sub']);
    $expired = Vendor::factory()->create(['business_type' => 'Sub']);
    $f['gc']->vendors()->attach([$noDoc->id, $expired->id]);
    foreach ([$noDoc, $expired] as $i => $vendor) {
        Check::create(['check_type' => 'Check', 'check_number' => '101'.$i, 'date' => '2025-04-01', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 75]);
    }
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $expired->id, 'belongs_to_vendor_id' => $f['gc']->id, 'number' => 'WC-OLD',
        'doc_filename' => 'old.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => '2025-12-31',
    ]));
    Check::create(['check_type' => 'Check', 'check_number' => '1004', 'date' => '2025-03-02', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $f['gc']->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 50]);

    auditPage($f)->call('verify_coverage_on_ewccv');

    expect(EwccvCoverageCheck::pluck('vendor_id')->all())->toBe([$f['sub']->id]);
});

it('a verify-only run records covered, not found, or still waiting, and subscribes to nothing', function () {
    $covered = Vendor::factory()->create(['state' => 'IL', 'address' => '1 Main St', 'business_name' => 'Kot Construction Inc']);
    $missing = Vendor::factory()->create(['state' => 'IL', 'address' => '2 Main St', 'business_name' => 'Smartech Electric Inc']);
    $hiccup = Vendor::factory()->create(['state' => 'IL', 'address' => '3 Main St', 'business_name' => 'JW Green Dump Inc']);
    $lapsed = Vendor::factory()->create(['state' => 'IL', 'address' => '4 Main St', 'business_name' => 'Lapsed Roofing Inc']);
    foreach ([[$covered, 'WCIL000171300'], [$missing, 'WC-SMART'], [$hiccup, 'WC-JW']] as [$vendor, $number]) {
        VendorDoc::withoutEvents(fn () => VendorDoc::create([
            'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => $number,
            'doc_filename' => $number.'.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => now()->addMonths(2)->toDateString(),
        ]));
    }
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $lapsed->id, 'belongs_to_vendor_id' => 1, 'number' => 'WC-LAPSED',
        'doc_filename' => 'l.pdf', 'effective_date' => '2024-01-01', 'expiration_date' => '2024-12-31',
    ]));
    foreach ([$covered, $missing, $hiccup, $lapsed] as $vendor) {
        EwccvCoverageCheck::request($vendor->id, 1, '2025-12-31');
    }

    $config = null;
    Process::fake(function ($process) use (&$config, $covered, $missing, $hiccup) {
        $file = preg_match('/(\/\S*ewccv_cfg_\S+)/', $process->command, $m) ? $m[1] : null;
        $config = $file && is_file($file) ? json_decode(file_get_contents($file), true) : null;

        return Process::result(json_encode([
            'success' => [[
                'vendorId' => $covered->id, 'businessName' => 'Kot Construction Inc', 'matchType' => 'employerName', 'score' => 0.67,
                'verifyOnly' => true, 'coverageDate' => '12/31/2025', 'policyNumber' => 'WCIL000171300', 'employerName' => 'KOT CONSTRUCTION INC',
                'details' => ['employerName' => 'KOT CONSTRUCTION INC', 'policyNumber' => 'WCIL000171300', 'stateCode' => 12],
                'searched' => [['type' => 'employer', 'term' => 'Kot Construction Inc', 'count' => 0], ['type' => 'employer', 'term' => 'Kot', 'count' => 1]],
            ]],
            'failed' => [
                ['vendorId' => $missing->id, 'businessName' => 'Smartech Electric Inc', 'reason' => 'no_match', 'searched' => [['type' => 'employer', 'term' => 'Smartech', 'count' => 2]], 'candidates' => [['employerName' => 'SMART TECH LLC', 'policyNumber' => 'X1', 'score' => 0.5]]],
                ['vendorId' => $hiccup->id, 'businessName' => 'JW Green Dump Inc', 'reason' => 'session_expired'],
            ],
            'skipped' => [],
            'sessionExpired' => true,
        ]));
    });

    $this->artisan('ewccv:scrape-tracking', [
        '--belongs-to-vendor-id' => 1, '--verify-only' => true, '--coverage-date' => '2025-12-31',
        '--vendor-id' => [$covered->id, $missing->id, $hiccup->id, $lapsed->id], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test',
    ])
        ->expectsOutputToContain('Lapsed Roofing Inc (ID: '.$lapsed->id.') — no workers comp policy active today, not searched')
        ->expectsOutputToContain('Coverage check as of 12/31/2025 for 3 vendor(s)')
        ->expectsOutputToContain('closest: SMART TECH LLC (policy X1, score 0.5)')
        ->expectsOutputToContain('Recorded EWCCV coverage for 3 coverage check(s).')
        ->assertExitCode(0);

    expect($config['verifyOnly'])->toBeTrue()
        ->and(collect($config['vendors'])->firstWhere('id', $covered->id))->toMatchArray(['coverageDate' => '12/31/2025', 'policyNumber' => 'WCIL000171300']);

    $row = fn (Vendor $v) => EwccvCoverageCheck::where('vendor_id', $v->id)->whereDate('coverage_date', '2025-12-31')->first();
    expect($row($covered))->toMatchArray(['status' => 'covered', 'policy_number' => 'WCIL000171300', 'employer_name' => 'KOT CONSTRUCTION INC', 'match_type' => 'employerName'])
        ->and($row($covered)->searched)->toHaveCount(2)
        ->and($row($missing))->toMatchArray(['status' => 'not_found', 'reason' => 'no_match'])
        ->and($row($missing)->candidates[0]['employerName'])->toBe('SMART TECH LLC')
        ->and($row($hiccup))->toMatchArray(['status' => 'pending', 'reason' => 'session_expired'])
        ->and($row($lapsed))->toMatchArray(['status' => 'not_found', 'reason' => 'no_active_policy'])
        ->and($row($lapsed)->label())->toStartWith('Not searched for 12/31/2025: no workers comp policy active today on file')
        ->and(collect($config['vendors'])->pluck('id')->all())->not->toContain($lapsed->id)
        ->and(EwccvCoverageCheck::pendingByDate(1))->toBe(['2025-12-31' => [$hiccup->id]])
        ->and(VendorDoc::withoutGlobalScopes()->where('vendor_id', $covered->id)->first()->options)->not->toHaveKey('ewccv');
});

it('a hand-off runs the pending coverage checks and the tracking lookups in one detached run', function () {
    config(['services.ewccv.bridge_token' => 'bridge-secret']);
    $a = Vendor::factory()->create();
    $b = Vendor::factory()->create();
    EwccvCoverageCheck::request($a->id, 1, '2025-12-31');
    EwccvCoverageCheck::request($b->id, 1, '2025-12-31');
    EwccvCoverageCheck::request($a->id, 1, '2024-12-31');
    EwccvCoverageCheck::factory()->covered()->create(['vendor_id' => $b->id, 'coverage_date' => '2024-12-31']);
    $tracking = coverageWcDoc();

    Queue::fake();
    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)])
        ->assertOk()
        ->assertJson(['ok' => true, 'pending_lookups' => 4]);
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);

    coverageBridgedSession();
    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->once()
        ->with([$tracking->vendor_id], 1, ['checks' => ['2024-12-31' => [$a->id], '2025-12-31' => [$a->id, $b->id]]])
        ->andReturn(11);
    (new RunPendingEwccvLookups)->handle($launcher);

    expect(ScrapeLauncher::commandLine([$a->id], 1, ['coverage_date' => '2025-12-31', 'verify_only' => true]))
        ->toContain("'--vendor-id={$a->id}' '--coverage-date=2025-12-31' '--verify-only'")
        ->and(ScrapeLauncher::commandLine([7], 1, ['checks' => ['2025-12-31' => [$a->id, $b->id]]]))
        ->toContain("'--vendor-id=7' '--check={$a->id}:2025-12-31' '--check={$b->id}:2025-12-31'");

    // While a run is on, nothing starts: the next hand-off covers the rest.
    $held = \Illuminate\Support\Facades\Cache::lock(\App\Support\Ewccv\ScraperLock::NAME, 60);
    expect($held->get())->toBeTrue();
    $idle = $this->mock(ScrapeLauncher::class);
    $idle->shouldReceive('launch')->never();
    (new RunPendingEwccvLookups)->handle($idle);
    $held->release();
});

it('one run carries tracking lookups and coverage checks, keyed per entry, and stamps each where it belongs', function () {
    $both = Vendor::factory()->create(['state' => 'IL', 'address' => '5 Main St', 'business_name' => 'Both Ways Inc']);
    $only = Vendor::factory()->create(['state' => 'IL', 'address' => '6 Main St', 'business_name' => 'Check Only Inc']);
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $only->id, 'belongs_to_vendor_id' => 1, 'number' => 'WC-ONLY',
        'doc_filename' => 'o.pdf', 'effective_date' => now()->subMonths(3)->toDateString(), 'expiration_date' => now()->addMonths(9)->toDateString(),
    ]));
    $doc = VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $both->id, 'belongs_to_vendor_id' => 1, 'number' => 'WC-BOTH',
        'doc_filename' => 'b.pdf', 'effective_date' => now()->subMonths(3)->toDateString(), 'expiration_date' => now()->addMonths(9)->toDateString(),
    ]));
    EwccvCoverageCheck::request($both->id, 1, '2025-12-31');
    EwccvCoverageCheck::request($only->id, 1, '2025-12-31');

    $config = null;
    Process::fake(function ($process) use (&$config, $both, $only) {
        $file = preg_match('/(\/\S*ewccv_cfg_\S+)/', $process->command, $m) ? $m[1] : null;
        $config = $file && is_file($file) ? json_decode(file_get_contents($file), true) : null;

        return Process::result(json_encode([
            'success' => [
                ['key' => (string) $both->id, 'vendorId' => $both->id, 'businessName' => 'Both Ways Inc', 'matchType' => 'policyNumber', 'tracked' => true, 'alreadyTracking' => false, 'matchedName' => 'BOTH WAYS INC', 'matchedPolicy' => 'WC-BOTH', 'details' => ['employerName' => 'BOTH WAYS INC', 'policyNumber' => 'WC-BOTH', 'fein' => '12-3456789', 'stateCode' => 12]],
                ['key' => $both->id.'@2025-12-31', 'vendorId' => $both->id, 'businessName' => 'Both Ways Inc', 'matchType' => 'policyNumber', 'verifyOnly' => true, 'coverageDate' => '12/31/2025', 'policyNumber' => 'WC-BOTH', 'employerName' => 'BOTH WAYS INC'],
            ],
            'failed' => [
                ['key' => $only->id.'@2025-12-31', 'vendorId' => $only->id, 'businessName' => 'Check Only Inc', 'reason' => 'no_results', 'searched' => [['type' => 'employer', 'term' => 'Check Only', 'count' => 0]]],
            ],
            'skipped' => [],
        ]));
    });

    $this->artisan('ewccv:scrape-tracking', [
        '--belongs-to-vendor-id' => 1, '--vendor-id' => [$both->id],
        '--check' => [$both->id.':2025-12-31', $only->id.':2025-12-31'], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test',
    ])
        ->expectsOutputToContain('Plus 2 coverage check(s) as of 2025-12-31')
        ->expectsOutputToContain('Recorded EWCCV coverage for 2 coverage check(s).')
        ->assertExitCode(0);

    expect(collect($config['vendors'])->pluck('key')->all())->toBe([(string) $both->id, $both->id.'@2025-12-31', $only->id.'@2025-12-31'])
        ->and(collect($config['vendors'])->firstWhere('key', $only->id.'@2025-12-31'))->toMatchArray(['verifyOnly' => true, 'coverageDate' => '12/31/2025'])
        ->and($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'tracking', 'matched_name' => 'BOTH WAYS INC'])
        ->and($doc->fresh()->options['ewccv']['ewccv_details'])->toBe(['employerName' => 'BOTH WAYS INC', 'policyNumber' => 'WC-BOTH', 'fein' => '12-3456789', 'stateCode' => 12])
        ->and(EwccvCoverageCheck::where('vendor_id', $both->id)->first())->toMatchArray(['status' => 'covered', 'policy_number' => 'WC-BOTH'])
        ->and(EwccvCoverageCheck::where('vendor_id', $only->id)->first())->toMatchArray(['status' => 'not_found', 'reason' => 'no_results'])
        // The check-only vendor's policy has never been looked up for tracking: that stays pending.
        ->and(EwccvCoverageCheck::pendingByDate(1))->toBe([])
        ->and(RunPendingEwccvLookups::pendingVendorIds(1))->toBe([$only->id]);

    // Every attempt is in the review log, with the doc it concerned.
    $logs = \App\Models\EwccvLookupLog::orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs[0])->toMatchArray(['vendor_id' => $both->id, 'vendor_doc_id' => $doc->id, 'kind' => 'tracking', 'outcome' => 'tracking', 'matched_name' => 'BOTH WAYS INC', 'matched_policy' => 'WC-BOTH', 'our_policy_number' => 'WC-BOTH'])
        ->and($logs[0]->detailLines())->toBe(['employerName: BOTH WAYS INC', 'policyNumber: WC-BOTH', 'fein: 12-3456789', 'stateCode: 12'])
        ->and($logs[1])->toMatchArray(['vendor_id' => $both->id, 'kind' => 'coverage_check', 'outcome' => 'covered'])
        ->and($logs[1]->coverage_date->toDateString())->toBe('2025-12-31')
        ->and($logs[2])->toMatchArray(['vendor_id' => $only->id, 'kind' => 'coverage_check', 'outcome' => 'not_found', 'reason' => 'no_results'])
        ->and($logs[2]->searchLines())->toBe(['employer "Check Only" → 0 result(s)']);
});

it('the vendor docs card opens a review log of every EWCCV attempt from the Verified column', function () {
    $f = auditFixture();
    \App\Models\EwccvLookupLog::create([
        'vendor_id' => $f['sub']->id, 'vendor_doc_id' => $f['doc']->id, 'belongs_to_vendor_id' => $f['gc']->id, 'kind' => 'tracking',
        'outcome' => 'no_match', 'reason' => 'no_match', 'our_policy_number' => 'WC-KOT-1', 'run_at' => now()->subHour(),
        'searched' => [['type' => 'employer', 'term' => 'Kot Construction', 'count' => 0, 'reason' => 'http_500'], ['type' => 'employer', 'term' => 'Kot', 'count' => 2]],
        'candidates' => [['employerName' => 'KOT CONSTRUCTION INC', 'policyNumber' => 'WC9', 'score' => 0.5]],
        'details' => ['employerName' => 'KOT CONSTRUCTION INC', 'fein' => '98-7654321'],
    ]);

    \Livewire\Livewire::withoutLazyLoading();
    \Livewire\Livewire::test(\App\Livewire\VendorDocs\VendorDocsCard::class, ['vendor' => $f['sub']])
        ->assertSee('click for the lookup log')
        ->call('showEwccvLog', $f['doc']->id)
        ->assertSee('EWCCV lookups')
        ->assertSee('results, none matched: no match')
        ->assertSee('Policy on file: WC-KOT-1')
        ->assertSee('employer "Kot Construction" → http 500')
        ->assertSee('employer "Kot" → 2 result(s)')
        ->assertSee('KOT CONSTRUCTION INC · policy WC9 · score 0.50')
        ->assertSee("EWCCV's record: employerName: KOT CONSTRUCTION INC · fein: 98-7654321", false);
});

it('refuses a credential handed over before, takes a new one even when marked reused, and tells the extension what is waiting', function () {
    Queue::fake();
    config(['services.ewccv.bridge_token' => 'bridge-secret']);
    $pending = coverageWcDoc();

    // The first hand-off of a credential is taken and starts the run.
    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)])
        ->assertOk();
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);

    // The same credential again — the minter's fallback copy, flagged or not — is refused.
    foreach ([['reused' => true], []] as $flag) {
        $this->withHeader('Authorization', 'Bearer bridge-secret')
            ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)] + $flag)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'reused' => true, 'pending_lookups' => 1])
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'Do one search on ewccv.com by hand'));
    }
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);

    // A new credential (a search by hand produced it) is taken even when marked reused.
    Cache::forget('ewccv:lookup-dispatched');
    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('n', 120), 'reused' => true])
        ->assertOk();
    expect(BridgedSession::raw()['access_token'])->toBe(str_repeat('n', 120))
        ->and(BridgedSession::hasSeen(str_repeat('a', 120)))->toBeTrue()
        ->and(BridgedSession::hasSeen(str_repeat('z', 120)))->toBeFalse();
    Storage::disk('local')->delete(BridgedSession::FILE);

    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->getJson('/api/ewccv/pending')
        ->assertOk()
        ->assertJson(['pending' => 1, 'running' => false, 'session' => 'none']);

    $held = \Illuminate\Support\Facades\Cache::lock(\App\Support\Ewccv\ScraperLock::NAME, 60);
    expect($held->get())->toBeTrue();
    $this->withHeader('Authorization', 'Bearer bridge-secret')->getJson('/api/ewccv/pending')->assertJson(['running' => true]);
    // A hand-off during a run is stored but starts nothing: the dispatch
    // flag is only set when a run is actually queued.
    Cache::forget('ewccv:lookup-dispatched');
    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('b', 120)])
        ->assertOk();
    expect(Cache::has('ewccv:lookup-dispatched'))->toBeFalse()
        ->and(BridgedSession::raw()['access_token'])->toBe(str_repeat('b', 120));
    $held->release();

    $this->flushHeaders()->getJson('/api/ewccv/pending')->assertUnauthorized();
});

it('a failed tracking lookup says what was searched and the closest employers EWCCV listed', function () {
    $doc = coverageWcDoc([
        'status' => 'failed', 'reason' => 'no_match', 'checked_at' => '2026-10-12T20:00:00+00:00',
        'searched' => [['type' => 'employer', 'term' => 'Mariusz Kot Construction, Inc', 'count' => 0], ['type' => 'employer', 'term' => 'Kot', 'count' => 3], ['type' => 'address', 'term' => '12 Main', 'count' => 0]],
        'candidates' => [['employerName' => 'KOT CONSTRUCTION INC', 'policyNumber' => 'WC1', 'score' => 0.5], ['employerName' => 'KOTA BUILDERS', 'policyNumber' => null, 'score' => 0.25]],
    ]);

    expect($doc->ewccv_summary['tip'])
        ->toContain('EWCCV lookup failed: no match')
        ->toContain('searched employer "Mariusz Kot Construction, Inc", employer "Kot", address "12 Main"')
        ->toContain('closest KOT CONSTRUCTION INC (policy WC1); KOTA BUILDERS')
        ->toContain('checked 10/12/2026');
});
