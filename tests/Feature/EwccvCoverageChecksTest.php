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
        ->assertSee('EWCCV coverage as of 12/29/2025')
        ->assertSee('2 waiting');

    expect(AuditShow::verificationCell($f['doc']))->toBe('WC verified 10/12/2026 (EWCCV)');
});

it('opening an audit asks EWCCV for coverage as of the end date minus two days for every paid sub, and shows the answers as they land', function () {
    Queue::fake();
    $f = auditFixture();

    // Opening the audit creates its request and queues the check; no browser session: it waits, the page says so.
    auditPage($f)
        ->assertSee('EWCCV: waiting')
        ->assertSee('Waiting for a browser session')
        ->assertSee('1 waiting')
        ->assertSee('Downloads open when EWCCV has answered for every sub');
    Queue::assertNotPushed(RunPendingEwccvLookups::class);

    $audit = \App\Models\AuditRequest::first();
    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe('pending')
        ->and($audit->coverage_date->toDateString())->toBe('2025-12-29')
        ->and($audit->vendors_checked)->toBe([$f['sub']->id])
        ->and($audit->requested_by_user_id)->toBe($f['user']->id);

    $check = EwccvCoverageCheck::where('vendor_id', $f['sub']->id)->first();
    expect($check)->not->toBeNull()
        ->and($check->belongs_to_vendor_id)->toBe($f['gc']->id)
        ->and($check->coverage_date->toDateString())->toBe('2025-12-29')
        ->and($check->status)->toBe('pending');

    // Asking again changes nothing; with a session at hand the run starts now.
    expect(EwccvCoverageCheck::request($f['sub']->id, $f['gc']->id, '2025-12-29'))->toBeFalse();
    $check->update(['status' => 'pending', 'reason' => 'session_expired']);
    coverageBridgedSession();
    auditPage($f)->call('verify_coverage_on_ewccv');
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);
    expect($check->fresh()->reason)->toBeNull();

    // The answers.
    $check->update(['status' => 'covered', 'policy_number' => 'WC-KOT-1', 'employer_name' => 'KOT CONSTRUCTION INC', 'checked_at' => now()]);
    auditPage($f)->assertSee('EWCCV: covered')->assertSee('Covered on 12/29/2025: KOT CONSTRUCTION INC, policy WC-KOT-1')->assertSee('1 covered');

    $check->update(['status' => 'not_found', 'reason' => 'no_match']);
    auditPage($f)->assertSee('EWCCV: not found')->assertSee('Not found on EWCCV for 12/29/2025 (no match)')->assertSee('1 not found');

    // A different end date is a different question, asked on first sight.
    expect(auditPage($f, '2025-06-30')->get('coverage_check_counts')['pending'])->toBe(1)
        ->and(\App\Models\AuditRequest::count())->toBe(2);
});

it('the audit package waits for the EWCCV answers, then is built and emailed with the certificates and the Excel', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Mail::fake();
    $f = auditFixture();
    Storage::fake('files');
    Storage::disk('files')->put('vendor_docs/kot.pdf', '%PDF-1.4 kot');

    // Opened: pending, downloads refused.
    $page = auditPage($f);
    expect($page->get('canDownload'))->toBeFalse();
    $page->call('download_documents');
    $audit = \App\Models\AuditRequest::first();
    expect($audit->status)->toBe('pending')->and($audit->pendingChecks())->toBe(1);

    // Nothing is sent while a check is pending.
    $this->mock(\App\Services\Audits\CertificateMerger::class)->shouldReceive('merge')->never();
    (new \App\Jobs\SendAuditWhenReady)->handle(app(\App\Services\Audits\CertificateMerger::class));
    \Illuminate\Support\Facades\Mail::assertNothingSent();

    // The answer lands: the package is built and emailed, downloads open.
    EwccvCoverageCheck::where('vendor_id', $f['sub']->id)->first()->update(['status' => 'covered', 'policy_number' => 'WC-KOT-1', 'employer_name' => 'KOT CONSTRUCTION INC', 'checked_at' => now()]);
    $merger = $this->mock(\App\Services\Audits\CertificateMerger::class);
    $merger->shouldReceive('merge')->once()->withArgs(fn (array $files, string $name) => count($files) === 1 && str_ends_with($files[0], 'kot.pdf') && str_starts_with($name, 'Audit-'.$f['gc']->id.'-'))->andReturn('%PDF-1.4 merged');
    (new \App\Jobs\SendAuditWhenReady)->handle($merger);

    $audit->refresh();
    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\AuditReadyMail::class, function (\App\Mail\AuditReadyMail $mail) use ($f, $audit) {
        $rendered = $mail->render();

        return $mail->hasTo($f['user']->email)
            && $mail->counts['covered'] === 1
            && $mail->attach === true
            && count($mail->attachments()) === 2
            && str_contains($mail->envelope()->subject, 'Audit package ready')
            && str_contains($rendered, 'Covered: 1')
            && str_contains($rendered, route('vendor_docs.audit.download', ['audit' => $audit->id, 'file' => 'pdf']))
            && str_contains($rendered, 'attached to this email as well');
    });
    expect($audit->status)->toBe('sent')
        ->and($audit->emailed_to)->toBe($f['user']->email)
        ->and(Storage::disk('local')->get($audit->pdf_path))->toBe('%PDF-1.4 merged')
        ->and(Storage::disk('local')->exists($audit->xlsx_path))->toBeTrue()
        ->and(auditPage($f)->get('canDownload'))->toBeTrue();
    auditPage($f)->assertSee('Package emailed to '.$f['user']->email);

    // The email's links serve the stored package to an admin of the company, and nobody else.
    $this->get(route('vendor_docs.audit.download', ['audit' => $audit->id, 'file' => 'pdf']))->assertOk()->assertDownload(basename($audit->pdf_path));
    $this->get(route('vendor_docs.audit.download', ['audit' => $audit->id, 'file' => 'xlsx']))->assertOk();
    $other = Vendor::factory()->create(['business_type' => 'GC']);
    $stranger = User::query()->create(['first_name' => 'Other', 'last_name' => 'Admin', 'email' => 'other-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $other->id]);
    $stranger->vendors()->attach($other->id, ['role_id' => 1]);
    $this->actingAs($stranger)->get(route('vendor_docs.audit.download', ['audit' => $audit->id, 'file' => 'pdf']))->assertForbidden();
});

it('a package too large to attach is emailed as links only', function () {
    \Illuminate\Support\Facades\Mail::fake();
    Queue::fake();
    $f = auditFixture();
    Storage::fake('files');
    Storage::disk('files')->put('vendor_docs/kot.pdf', '%PDF-1.4 kot');
    auditPage($f);
    $audit = \App\Models\AuditRequest::first();
    EwccvCoverageCheck::where('vendor_id', $f['sub']->id)->first()->update(['status' => 'covered', 'checked_at' => now()]);
    $merger = $this->mock(\App\Services\Audits\CertificateMerger::class);
    $merger->shouldReceive('merge')->once()->andReturn('%PDF-1.4 '.str_repeat('x', 8 * 1024 * 1024));
    (new \App\Jobs\SendAuditWhenReady)->handle($merger);

    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\AuditReadyMail::class, fn (\App\Mail\AuditReadyMail $mail) => $mail->attach === false && $mail->attachments() === [] && str_contains($mail->render(), 'too large to attach'));
    expect($audit->fresh()->status)->toBe('sent');
});

it('a general audit, with nothing to ask EWCCV, is finished at once', function () {
    Queue::fake();
    $f = auditFixture();
    Livewire::test(AuditShow::class, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'audit_type' => 'general', 'bank_account_ids' => [$f['bank']->id]])
        ->assertDontSee('EWCCV coverage as of');
    $audit = \App\Models\AuditRequest::first();
    expect($audit->audit_type)->toBe('general')->and($audit->coverage_date)->toBeNull()->and($audit->pendingChecks())->toBe(0);
    Queue::assertPushed(\App\Jobs\SendAuditWhenReady::class);
});

it('leaves the company itself, employees, retail and categorised vendors, and subs with no policy for the checked date or today out of the coverage checks', function () {
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
    // A certificate that covered the checked date (12/29/2025) but has expired since still counts for THIS audit.
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $expired->id, 'belongs_to_vendor_id' => $f['gc']->id, 'number' => 'WC-OLD',
        'doc_filename' => 'old.pdf', 'effective_date' => '2025-01-01', 'expiration_date' => '2025-12-31',
    ]));
    $gone = Vendor::factory()->create(['business_type' => 'Sub']);
    $f['gc']->vendors()->attach($gone->id);
    Check::create(['check_type' => 'Check', 'check_number' => '1020', 'date' => '2025-02-01', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $gone->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 75]);
    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $gone->id, 'belongs_to_vendor_id' => $f['gc']->id, 'number' => 'WC-GONE-EARLY',
        'doc_filename' => 'gone.pdf', 'effective_date' => '2024-06-01', 'expiration_date' => '2025-06-01',
    ]));
    Check::create(['check_type' => 'Check', 'check_number' => '1004', 'date' => '2025-03-02', 'bank_account_id' => $f['bank']->id, 'vendor_id' => $f['gc']->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id, 'amount' => 50]);

    auditPage($f)->call('verify_coverage_on_ewccv');

    // The sub active today and the one whose certificate covered 12/29/2025; not the one that expired in mid-2025.
    expect(EwccvCoverageCheck::pluck('vendor_id')->sort()->values()->all())->toBe(collect([$f['sub']->id, $expired->id])->sort()->values()->all());
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
    $held = new \App\Support\Ewccv\ScraperLock;
    expect($held->acquire())->toBeTrue();
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

    $logFile = sys_get_temp_dir().'/ewccv-log-'.uniqid().'.log';
    config(['logging.channels.ewccv' => ['driver' => 'single', 'path' => $logFile]]);
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

    // Every attempt is a line in the ewccv log channel (read in /log-viewer)…
    $written = file_get_contents($logFile);
    expect($written)->toContain('tracking lookup | vendor '.$both->id.' Both Ways Inc | tracking | policy on file WC-BOTH | matched BOTH WAYS INC, policy WC-BOTH via policyNumber')
        ->toContain('coverage check as of 2025-12-31 | vendor '.$only->id.' Check Only Inc | not_found (no_results) | policy on file — | matched — | searched: employer "Check Only" → 0 result(s)');

    // …and a row in the review table, with the doc it concerned.
    $logs = \App\Models\EwccvLookupLog::orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs[0])->toMatchArray(['vendor_id' => $both->id, 'vendor_doc_id' => $doc->id, 'kind' => 'tracking', 'outcome' => 'tracking', 'matched_name' => 'BOTH WAYS INC', 'matched_policy' => 'WC-BOTH', 'our_policy_number' => 'WC-BOTH'])
        ->and($logs[0]->detailLines())->toBe(['employerName: BOTH WAYS INC', 'policyNumber: WC-BOTH', 'fein: 12-3456789', 'stateCode: 12'])
        ->and($logs[1])->toMatchArray(['vendor_id' => $both->id, 'kind' => 'coverage_check', 'outcome' => 'covered'])
        ->and($logs[1]->coverage_date->toDateString())->toBe('2025-12-31')
        ->and($logs[2])->toMatchArray(['vendor_id' => $only->id, 'kind' => 'coverage_check', 'outcome' => 'not_found', 'reason' => 'no_results'])
        ->and($logs[2]->searchLines())->toBe(['employer "Check Only" → 0 result(s)']);
});

it('the vendor docs page shows the verification only; the attempts are reviewed in /log-viewer, not there', function () {
    $f = auditFixture();

    \Livewire\Livewire::withoutLazyLoading();
    \Livewire\Livewire::test(\App\Livewire\VendorDocs\VendorDocsCard::class, ['vendor' => $f['sub']])
        ->assertSee('10/12/2026')
        ->assertDontSee('lookup log')
        ->assertDontSee('showEwccvLog');
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

    $held = new \App\Support\Ewccv\ScraperLock;
    expect($held->acquire())->toBeTrue();
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

it('search hints: names, addresses and the FEIN ride along to the scraper, a lookup is queued, and a matched FEIN is learned', function () {
    $vendor = Vendor::factory()->create(['state' => 'IL', 'address' => '102 Brook Rd', 'city' => 'Prospect Heights', 'zip_code' => '60070', 'business_name' => 'Smartech Electric, Inc']);
    $doc = VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => '121562826064403998',
        'doc_filename' => 's.pdf', 'effective_date' => now()->subMonths(2)->toDateString(), 'expiration_date' => now()->addMonths(10)->toDateString(),
        'options' => ['ewccv' => ['status' => 'failed', 'reason' => 'no_match', 'checked_at' => now()->toIso8601String()]],
    ]));

    Queue::fake();
    $this->artisan('ewccv:hints', ['vendor' => $vendor->id, '--name' => ['Smartech Inc', 'Smart Tech Electric'], '--address' => ['3548 Grandmore Ave, Gurnee, IL 60031'], '--check-as-of' => '2025-06-01'])
        ->expectsOutputToContain('Queued a fresh tracking lookup for 1 active workers comp doc(s)')
        ->expectsOutputToContain('Queued a coverage check as of 2025-06-01')
        ->assertExitCode(0);
    Queue::assertNotPushed(RunPendingEwccvLookups::class);

    // With a credential on file the run starts at once.
    coverageBridgedSession();
    $this->artisan('ewccv:hints', ['vendor' => $vendor->id])->expectsOutputToContain('the run starts now')->assertExitCode(0);
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);
    Storage::disk('local')->delete(BridgedSession::FILE);

    expect(\App\Support\Ewccv\SearchHints::for($vendor->fresh()))->toBe([
        'known_record' => null,
        'names' => ['Smartech Inc', 'Smart Tech Electric'],
        'addresses' => [['address' => '3548 Grandmore Ave', 'city' => 'Gurnee', 'zip' => '60031']],
        'fein' => null,
    ])
        ->and($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'pending', 'reason' => 'retry_with_hints'])
        ->and(RunPendingEwccvLookups::pendingVendorIds(1))->toBe([$vendor->id])
        ->and(EwccvCoverageCheck::pendingByDate(1))->toBe(['2025-06-01' => [$vendor->id]]);

    // The run carries the hints and, matching by name at the Gurnee address, learns the FEIN.
    $config = null;
    Process::fake(function ($process) use (&$config, $vendor) {
        $file = preg_match('/(\/\S*ewccv_cfg_\S+)/', $process->command, $m) ? $m[1] : null;
        $config = $file && is_file($file) ? json_decode(file_get_contents($file), true) : null;

        return Process::result(json_encode([
            'success' => [[
                'key' => $vendor->id.'@2025-06-01', 'vendorId' => $vendor->id, 'businessName' => 'Smartech Electric, Inc', 'matchType' => 'employerName', 'score' => 1,
                'verifyOnly' => true, 'coverageDate' => '06/01/2025', 'policyNumber' => 'NVWV009000377800', 'employerName' => 'SMART TECH ELECTRIC INC',
                'details' => ['employerName' => 'SMART TECH ELECTRIC INC', 'policyNumber' => 'NVWV009000377800', 'fein' => '36-1234567', 'stateCode' => 12],
            ]],
            'failed' => [['key' => (string) $vendor->id, 'vendorId' => $vendor->id, 'businessName' => 'Smartech Electric, Inc', 'reason' => 'no_results', 'searched' => [['type' => 'address', 'term' => '3548 Grandmore Ave', 'count' => 0]]]],
            'skipped' => [],
        ]));
    });
    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--vendor-id' => [$vendor->id], '--check' => [$vendor->id.':2025-06-01'], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])
        ->assertExitCode(0);

    $entry = collect($config['vendors'])->firstWhere('key', (string) $vendor->id);
    expect($entry)->toMatchArray(['aliases' => ['Smartech Inc', 'Smart Tech Electric'], 'altAddresses' => [['address' => '3548 Grandmore Ave', 'city' => 'Gurnee', 'zip' => '60031']], 'fein' => null])
        ->and(\App\Support\Ewccv\SearchHints::for($vendor->fresh())['fein'])->toBe('36-1234567')
        ->and(EwccvCoverageCheck::where('vendor_id', $vendor->id)->first())->toMatchArray(['status' => 'covered', 'employer_name' => 'SMART TECH ELECTRIC INC']);

    $this->artisan('ewccv:hints', ['vendor' => $vendor->id, '--show' => true])->expectsOutputToContain('"fein": "36-1234567"')->assertExitCode(0);
});

it('tracks a policy from a record EWCCV returned before, without a search or a browser credential', function () {
    $vendor = Vendor::factory()->create(['state' => 'IL', 'address' => '102 Brook Rd', 'business_name' => 'Smartech Electric, Inc']);
    $doc = VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => '121562826064403998',
        'doc_filename' => 's.pdf', 'effective_date' => now()->subMonths(7)->toDateString(), 'expiration_date' => now()->addMonths(4)->toDateString(),
        'options' => ['ewccv' => ['status' => 'failed', 'reason' => 'search_unavailable']],
    ]));

    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->once()->with([$vendor->id], 1)->andReturn(777);
    $this->artisan('ewccv:track-known', ['vendor' => $vendor->id, '--written-policy-id' => '102756218', '--policy' => 'WC533SB2784N016', '--employer' => 'SMARTECH ELECTRIC INC'])
        ->expectsOutputToContain('Known EWCCV record saved for Smartech Electric, Inc: SMARTECH ELECTRIC INC, policy WC533SB2784N016 (id 102756218); 1 doc(s) queued.')
        ->expectsOutputToContain('Subscribing now (detached run, pid 777); no browser credential needed.')
        ->assertExitCode(0);

    expect(\App\Support\Ewccv\SearchHints::for($vendor->fresh())['known_record'])->toBe([
        'writtenPolicyId' => '102756218', 'policyNumber' => 'WC533SB2784N016', 'employerName' => 'SMARTECH ELECTRIC INC', 'fein' => '', 'for_policy_number' => '121562826064403998',
    ])
        ->and($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'pending', 'reason' => 'track_known_record']);

    // The run carries the record for that certificate, and stamps the doc from the subscription it made.
    $config = null;
    Process::fake(function ($process) use (&$config, $vendor) {
        $file = preg_match('/(\/\S*ewccv_cfg_\S+)/', $process->command, $m) ? $m[1] : null;
        $config = $file && is_file($file) ? json_decode(file_get_contents($file), true) : null;

        return Process::result(json_encode(['success' => [[
            'key' => (string) $vendor->id, 'vendorId' => $vendor->id, 'businessName' => 'Smartech Electric, Inc', 'matchType' => 'known_record', 'score' => 1,
            'matchedName' => 'SMARTECH ELECTRIC INC', 'matchedPolicy' => 'WC533SB2784N016', 'tracked' => true, 'alreadyTracking' => false, 'subscriptionId' => 55,
            'details' => ['writtenPolicyId' => '102756218', 'policyNumber' => 'WC533SB2784N016', 'employerName' => 'SMARTECH ELECTRIC INC', 'fein' => ''],
        ]], 'failed' => [], 'skipped' => []]));
    });
    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--vendor-id' => [$vendor->id], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])->assertExitCode(0);

    expect($config['vendors'][0]['knownRecord'])->toMatchArray(['writtenPolicyId' => '102756218', 'policyNumber' => 'WC533SB2784N016'])
        ->and($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'tracking', 'match_type' => 'known_record', 'matched_policy' => 'WC533SB2784N016', 'subscription_id' => 55]);

    // A new certificate with another number gets a search, not the old record.
    $doc->forceFill(['number' => 'WC-RENEWED-2027'])->saveQuietly();
    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--vendor-id' => [$vendor->id], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])->assertExitCode(0);
    expect($config['vendors'][0])->not->toHaveKey('knownRecord');
});

it('queues a coverage check two days before a workers comp doc expires, and a covered answer becomes its Verified date', function () {
    $soon = Vendor::factory()->create(['business_name' => 'M Power Solution Company']);
    $later = Vendor::factory()->create();
    $expired = Vendor::factory()->create();
    $mk = fn (Vendor $v, string $number, string $expires) => VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $v->id, 'belongs_to_vendor_id' => 1, 'number' => $number, 'doc_filename' => $number.'.pdf',
        'effective_date' => Carbon\Carbon::parse($expires)->subYear()->toDateString(), 'expiration_date' => $expires,
        'options' => ['ewccv' => ['status' => 'failed', 'reason' => 'recaptcha_failed_ui', 'checked_at' => '2026-09-10T16:43:14+00:00']],
    ]));
    $doc = $mk($soon, '6-JUB-A421140-4-25', now()->addDays(2)->toDateString());
    $mk($later, 'WC-LATER', now()->addDays(20)->toDateString());
    $mk($expired, 'WC-GONE', now()->subDay()->toDateString());

    Queue::fake();
    $this->artisan('ewccv:expiring-checks', ['--belongs-to-vendor-id' => 1])
        ->expectsOutputToContain('queued: M Power Solution Company — 6-JUB-A421140-4-25 expires '.now()->addDays(2)->format('m/d/Y').', check as of '.now()->format('m/d/Y'))
        ->expectsOutputToContain('Expiring workers comp docs: 1; coverage checks queued: 1.')
        ->assertExitCode(0);
    expect(EwccvCoverageCheck::pluck('vendor_id')->all())->toBe([$soon->id])
        ->and(EwccvCoverageCheck::first()->coverage_date->toDateString())->toBe(now()->toDateString());

    // Running again queues nothing new.
    $this->artisan('ewccv:expiring-checks', ['--belongs-to-vendor-id' => 1])->expectsOutputToContain('coverage checks queued: 0.');

    // Before the answer the doc is unverified (its last lookup failed) and says the check is queued; a covered answer verifies it for that day.
    expect($doc->fresh()->verification_summary['verified_at'])->toBeNull()
        ->and($doc->fresh()->verification_summary['tip'])->toContain('EWCCV lookup failed: recaptcha failed ui · checked 09/10/2026 · EWCCV check as of '.now()->format('m/d/Y').' queued, waiting for a browser session');
    EwccvCoverageCheck::first()->update(['status' => 'covered', 'employer_name' => 'M POWER SOLUTION COMPANY', 'policy_number' => '6JUBA4211404-25', 'checked_at' => now()]);
    $summary = $doc->fresh()->verification_summary;
    expect($summary['verified_at'])->toBe(now()->format('m/d/Y'))
        ->and($summary['tip'])->toContain('EWCCV: covered on '.now()->format('m/d/Y').' (M POWER SOLUTION COMPANY, policy 6JUBA4211404-25) · checked '.now()->format('m/d/Y'))
        ->and(\App\Livewire\VendorDocs\AuditShow::verificationCell($doc->fresh()))->toBe('WC verified '.now()->format('m/d/Y').' (EWCCV)');

    // The command accepts a check for a day the policy covered even after it expired (a retroactive check).
    $expiredDoc = VendorDoc::withoutGlobalScopes()->where('number', 'WC-GONE')->first();
    EwccvCoverageCheck::request($expired->id, 1, now()->subDays(3)->toDateString());
    Process::fake(['*' => Process::result(json_encode(['success' => [], 'failed' => [['key' => $expired->id.'@'.now()->subDays(3)->toDateString(), 'vendorId' => $expired->id, 'businessName' => 'x', 'reason' => 'no_results', 'searched' => []]], 'skipped' => []]))]);
    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--check' => [$expired->id.':'.now()->subDays(3)->toDateString()], '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])
        ->expectsOutputToContain('Plus 1 coverage check(s)')
        ->assertExitCode(0);
    expect(EwccvCoverageCheck::where('vendor_id', $expired->id)->first())->toMatchArray(['status' => 'not_found', 'reason' => 'no_results']);
});

it('hands the extension a young sign-in link, or requests a fresh one by email', function () {
    config(['services.ewccv.bridge_token' => 'bridge-secret']);
    Queue::fake();
    Cache::forget('ewccv:sign-in-link-requested');
    $file = \App\Support\Ewccv\SignInLink::file();
    @mkdir(dirname($file), 0775, true);
    @unlink($file);

    $this->withHeader('Authorization', 'Bearer bridge-secret')->getJson('/api/ewccv/sign-in-link')
        ->assertOk()->assertJson(['url' => null, 'requested' => true]);
    Queue::assertPushed(\App\Jobs\RequestEwccvSignInLink::class, 1);

    \App\Support\Ewccv\SignInLink::remember('https://www.ewccv.com/cvs/login/?t=fresh.token.here');
    $this->withHeader('Authorization', 'Bearer bridge-secret')->getJson('/api/ewccv/sign-in-link')
        ->assertOk()->assertJson(['url' => 'https://www.ewccv.com/cvs/login/?t=fresh.token.here', 'requested' => false]);

    file_put_contents($file, json_encode(['url' => 'https://www.ewccv.com/cvs/login/?t=old', 'cached_at' => now()->subHours(11)->toIso8601String()]));
    expect(\App\Support\Ewccv\SignInLink::cached())->toBeNull();
    @unlink($file);

    $this->flushHeaders()->getJson('/api/ewccv/sign-in-link')->assertUnauthorized();
});

it('an audit whose period has not ended is checked as of today, and says so', function () {
    Queue::fake();
    $f = auditFixture();
    $end = now()->addMonths(10)->toDateString();
    expect(\App\Models\AuditRequest::coverageDateFor($end)->toDateString())->toBe(now()->toDateString())
        ->and(\App\Models\AuditRequest::coverageDateIsClamped($end))->toBeTrue()
        ->and(\App\Models\AuditRequest::coverageDateIsClamped('2025-12-31'))->toBeFalse();

    auditPage($f, $end)
        ->assertSee('EWCCV coverage as of '.now()->format('m/d/Y').' (the period has not ended; checked as of today)');
    expect(EwccvCoverageCheck::where('vendor_id', $f['sub']->id)->first()->coverage_date->toDateString())->toBe(now()->toDateString());
});
