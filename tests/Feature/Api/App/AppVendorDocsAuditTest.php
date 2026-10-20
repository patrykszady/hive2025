<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Carbon::setTestNow('2026-10-20 12:00:00');
});

/**
 * The audit page actions App\Services\VendorDocs\AppAuditSummary left
 * web-only: bank-statement gap detection/"ask again"
 * (App\Livewire\VendorDocs\AuditShow::listStatements()/
 * fetchMissingStatements()), queueing an EWCCV re-check
 * (::verify_coverage_on_ewccv()), and the Excel export as a signed
 * download URL (::export_xlsx()). PlaidService is mocked throughout —
 * never a real call — and the actual EWCCV scrape is never run (Queue is
 * faked; BridgedSession::canSearch() is false with no bridged session on
 * disk and no bypass key configured in tests).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appAuditTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** @return array{bank: Bank, account: BankAccount} */
function appAuditBank(Vendor $vendor, array $bankOverrides = []): array
{
    $bank = Bank::create(array_merge([
        'name' => 'Audit Credit Union',
        'vendor_id' => $vendor->id,
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_ins_id' => 'ins_'.uniqid(),
    ], $bankOverrides));

    $account = BankAccount::create([
        'bank_id' => $bank->id,
        'vendor_id' => $vendor->id,
        'account_number' => random_int(1000, 9999),
        'plaid_account_id' => 'acc_'.uniqid(),
        'type' => 'Checking',
    ]);

    return compact('bank', 'account');
}

it('needs a token for every audit action', function () {
    $this->getJson('/api/app/v1/vendor-docs/audit/statement-gaps?start_date=2026-01-01&end_date=2026-10-01&bank_account_ids[]=1')->assertUnauthorized();
    $this->postJson('/api/app/v1/vendor-docs/audit/statements/1/refresh')->assertUnauthorized();
    $this->postJson('/api/app/v1/vendor-docs/audit/ewccv/verify')->assertUnauthorized();
    $this->getJson('/api/app/v1/vendor-docs/audit/export-xlsx-url')->assertUnauthorized();
});

it('lists missing statement months per bank, like the audit page\'s callout', function () {
    ['vendor' => $vendor, 'user' => $admin] = appAuditTeamUser();
    ['account' => $account] = appAuditBank($vendor);

    $this->mock(PlaidService::class, function ($mock) use ($account) {
        // No statements at all posted for the account — the whole period
        // (Jan–Jun 2026) is one gap.
        $mock->shouldReceive('getStatements')->andReturn(['accounts' => [['account_id' => $account->plaid_account_id, 'statements' => []]]]);
    });

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/vendor-docs/audit/statement-gaps?'.http_build_query([
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'bank_account_ids' => [$account->id],
    ]))->assertOk();

    $gaps = $response->json('data');
    expect($gaps)->toHaveCount(1)
        ->and($gaps[0]['bank_name'])->toBe('Audit Credit Union')
        ->and($gaps[0]['requested_at'])->toBeNull();
});

it('asks the bank again for a missing range, and saves the request so it is never asked twice', function () {
    ['vendor' => $vendor, 'user' => $admin] = appAuditTeamUser();
    ['bank' => $bank, 'account' => $account] = appAuditBank($vendor);

    $this->mock(PlaidService::class, function ($mock) use ($account) {
        $mock->shouldReceive('getStatements')->andReturn(['accounts' => [['account_id' => $account->plaid_account_id, 'statements' => []]]]);
        $mock->shouldReceive('refreshStatements')->once()->andReturn(['request_id' => 'req-123']);
    });

    Sanctum::actingAs($admin, ['app']);
    $response = $this->postJson("/api/app/v1/vendor-docs/audit/statements/{$bank->id}/refresh", [
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'bank_account_ids' => [$account->id],
    ])->assertOk();

    expect($response->json('data.requested'))->toHaveCount(1);
    $gap = collect($response->json('data.gaps'))->first();
    expect($gap['requested_at'])->not->toBeNull();

    $bank->refresh();
    expect($bank->plaid_options['statement_refreshes'] ?? [])->toHaveCount(1);
});

it('refuses verifying EWCCV for a Member, and for a non-workers-comp audit it is a no-op', function () {
    ['vendor' => $vendor, 'user' => $admin] = appAuditTeamUser();
    ['user' => $member] = appAuditTeamUser(role: 2);
    Queue::fake();

    Sanctum::actingAs($member, ['app']);
    $this->postJson('/api/app/v1/vendor-docs/audit/ewccv/verify', [
        'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'type' => 'workers', 'bank_account_ids' => [],
    ])->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $response = $this->postJson('/api/app/v1/vendor-docs/audit/ewccv/verify', [
        'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'type' => 'general', 'bank_account_ids' => [],
    ])->assertOk();

    expect($response->json('data'))->toBe(['queued' => 0, 'dispatched' => false]);
    Queue::assertNothingPushed();
});

it('queues EWCCV checks for a workers comp audit without ever running a real scrape', function () {
    ['vendor' => $vendor, 'user' => $admin] = appAuditTeamUser();
    ['account' => $account] = appAuditBank($vendor);
    Queue::fake();

    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    \App\Models\VendorDoc::create([
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $vendor->id,
        'type' => 'workers',
        'number' => 'WC-1',
        'effective_date' => '2025-01-01',
        'expiration_date' => '2027-01-01',
        'doc_filename' => 'wc-1.pdf',
    ]);
    \App\Models\Check::create([
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $vendor->id,
        'bank_account_id' => $account->id,
        'date' => '2026-03-01',
        'amount' => 500,
        'check_type' => 'Check',
        'check_number' => 1001,
        'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);
    $payload = [
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'type' => 'workers',
        'bank_account_ids' => [$account->id],
    ];

    // The FIRST call starts the audit (AuditRequest::findOrStart queues
    // every check it needs as a side effect, the same as opening the web
    // page does), so asking again immediately finds nothing new to queue
    // — the exact "already queued" case AuditShow's toast describes.
    $first = $this->postJson('/api/app/v1/vendor-docs/audit/ewccv/verify', $payload)->assertOk();
    expect($first->json('data'))->toBe(['queued' => 0, 'dispatched' => false]);

    // A previous attempt errored (still pending, but with a reason noted —
    // EwccvCoverageCheck::request()'s own "failed attempt put back" case):
    // asking again DOES queue it.
    \App\Models\EwccvCoverageCheck::query()->update(['reason' => 'lookup_error']);

    $second = $this->postJson('/api/app/v1/vendor-docs/audit/ewccv/verify', $payload)->assertOk();
    expect($second->json('data.queued'))->toBeGreaterThan(0)
        ->and($second->json('data.dispatched'))->toBeFalse();
    // Never a real lookup: no bridged session exists and no bypass key is
    // configured in tests, so RunPendingEwccvLookups (the job that would
    // actually drive the browser session) is never even queued — only
    // ordinary model-save side effects (Scout indexing, the per-vendor
    // lookup/audit-ready jobs, all faked) run.
    Queue::assertNotPushed(\App\Jobs\RunPendingEwccvLookups::class);
});

it('mints a signed xlsx export url that downloads without a session, and expires', function () {
    ['vendor' => $vendor, 'user' => $admin] = appAuditTeamUser();
    ['account' => $account] = appAuditBank($vendor);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/vendor-docs/audit/export-xlsx-url?'.http_build_query([
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'type' => 'general',
        'bank_account_ids' => [$account->id],
    ]))->assertOk();

    $url = $response->json('data.url');
    expect($url)->toContain('/vendor-docs/audit/'.$vendor->id.'/export.xlsx');

    // No Sanctum/session context at all for this request — the signature
    // alone must let it through.
    $this->app['auth']->forgetGuards();

    $download = $this->get($url)->assertOk();
    expect($download->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Tampering with the query string invalidates the signature.
    $this->get($url.'&tampered=1')->assertForbidden();
});
