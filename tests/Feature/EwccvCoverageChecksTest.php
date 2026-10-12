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
        'doc_filename' => 'kot.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => '2025-12-31',
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

    auditPage($f)
        ->assertSee('WC verified 10/12/2026')
        ->assertSee('not verified')
        ->assertSee('EWCCV coverage as of 12/31/2025')
        ->assertSee('not checked yet (2 subs)');

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

it('leaves the company itself, employees, retail and categorised vendors out of the coverage checks', function () {
    $f = auditFixture();
    $retail = Vendor::factory()->create(['business_type' => 'Retail']);
    $f['gc']->vendors()->attach($retail->id);
    Check::create(['check_type' => 'Check', 'check_number' => '1003', 'date' => '2025-03-01', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $retail->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 50]);
    Check::create(['check_type' => 'Check', 'check_number' => '1004', 'date' => '2025-03-02', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $f['gc']->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 50]);

    auditPage($f)->call('verify_coverage_on_ewccv');

    expect(EwccvCoverageCheck::pluck('vendor_id')->all())->toBe([$f['sub']->id]);
});

it('a verify-only run records covered, not found, or still waiting, and subscribes to nothing', function () {
    $covered = Vendor::factory()->create(['state' => 'IL', 'address' => '1 Main St', 'business_name' => 'Kot Construction Inc']);
    $missing = Vendor::factory()->create(['state' => 'IL', 'address' => '2 Main St', 'business_name' => 'Smartech Electric Inc']);
    $hiccup = Vendor::factory()->create(['state' => 'IL', 'address' => '3 Main St', 'business_name' => 'JW Green Dump Inc']);
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $covered->id, 'belongs_to_vendor_id' => 1, 'number' => 'WCIL000171300',
        'doc_filename' => 'k.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => '2025-12-31',
    ]));
    foreach ([$covered, $missing, $hiccup] as $vendor) {
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
        '--vendor-id' => [$covered->id, $missing->id, $hiccup->id], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test',
    ])
        ->expectsOutputToContain('Coverage check as of 12/31/2025 for 3 vendor(s)')
        ->expectsOutputToContain('closest: SMART TECH LLC (policy X1, score 0.5)')
        ->expectsOutputToContain('Recorded EWCCV coverage on 12/31/2025 for 3 vendor(s).')
        ->assertExitCode(0);

    expect($config['verifyOnly'])->toBeTrue()
        ->and(collect($config['vendors'])->firstWhere('id', $covered->id))->toMatchArray(['coverageDate' => '12/31/2025', 'policyNumber' => 'WCIL000171300']);

    $row = fn (Vendor $v) => EwccvCoverageCheck::where('vendor_id', $v->id)->whereDate('coverage_date', '2025-12-31')->first();
    expect($row($covered))->toMatchArray(['status' => 'covered', 'policy_number' => 'WCIL000171300', 'employer_name' => 'KOT CONSTRUCTION INC', 'match_type' => 'employerName'])
        ->and($row($covered)->searched)->toHaveCount(2)
        ->and($row($missing))->toMatchArray(['status' => 'not_found', 'reason' => 'no_match'])
        ->and($row($missing)->candidates[0]['employerName'])->toBe('SMART TECH LLC')
        ->and($row($hiccup))->toMatchArray(['status' => 'pending', 'reason' => 'session_expired'])
        ->and(EwccvCoverageCheck::pendingByDate(1))->toBe(['2025-12-31' => [$hiccup->id]])
        ->and(VendorDoc::withoutGlobalScopes()->where('vendor_id', $covered->id)->first()->options)->not->toHaveKey('ewccv');
});

it('a hand-off runs the pending coverage checks, one detached run per date, alongside the tracking lookups', function () {
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
    $launcher->shouldReceive('launch')->once()->with([$tracking->vendor_id], 1)->andReturn(11);
    $launcher->shouldReceive('launch')->once()->with([$a->id], 1, ['coverage_date' => '2024-12-31', 'verify_only' => true])->andReturn(12);
    $launcher->shouldReceive('launch')->once()->with([$a->id, $b->id], 1, ['coverage_date' => '2025-12-31', 'verify_only' => true])->andReturn(13);
    (new RunPendingEwccvLookups)->handle($launcher);

    expect(ScrapeLauncher::commandLine([$a->id], 1, ['coverage_date' => '2025-12-31', 'verify_only' => true]))
        ->toContain("'--vendor-id={$a->id}' '--coverage-date=2025-12-31' '--verify-only'");
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
