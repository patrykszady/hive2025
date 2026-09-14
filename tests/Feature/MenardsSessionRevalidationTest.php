<?php

use App\Http\Controllers\MenardsSyncStatusController;
use App\Models\Vendor;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const RECEIPT_TITLE = 'Receipt Lookup at Menards® - Google Chrome';
const ACCOUNT_TITLE = 'Account Overview at Menards® - Google Chrome';
const SIGNIN_TITLE = 'Sign In at Menards® - Google Chrome';

/**
 * The browser service with everything that touches X stubbed out. $title is
 * what the window shows; $landings maps a navigated URL to the title it ends
 * on ('' = never matched what the caller accepted).
 */
function menardsBrowserStub(string &$title, array $landings = []): MenardsRemoteBrowserService
{
    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $browser->shouldReceive('xdotoolAvailable')->andReturn(true);
    $browser->shouldReceive('displayUp')->andReturn(true);
    // A closure, not an arrow function: the title must be read live, not captured.
    $browser->shouldReceive('windowTitle')->andReturnUsing(function () use (&$title) {
        return $title;
    });
    $browser->shouldReceive('loadAndWait')->andReturnUsing(function (string $url, array $accept) use (&$title, $landings) {
        $landed = $landings[$url] ?? '';

        if ($landed !== '') {
            $title = $landed;
        }

        foreach ($accept as $needle) {
            if (str_contains($landed, $needle)) {
                return $landed;
            }
        }

        return '';
    });
    $browser->shouldReceive('click', 'xdo', 'captureChallengeScreenshot')->andReturnNull();
    $browser->shouldReceive('clickChallengeCheckbox')->andReturn(false);

    return $browser;
}

function expiredReport(): void
{
    Cache::put(MenardsSyncStatusController::CACHE_KEY, [
        'ok' => false,
        'error' => "/main/my-account/receipt-lookup/initialize.ajx returned Imperva's challenge page — the browser session has expired as far as Menards is concerned until the \"I am human\" check is cleared.",
        'receipts' => null,
        'session_expired' => true,
        'at' => now()->subHours(3)->toIso8601String(),
    ], now()->addMonth());
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->subHour()->toIso8601String()], now()->addMonth());
}

it('verifies a reported-expired session with a real page load instead of trusting the report', function () {
    expiredReport();
    $title = ACCOUNT_TITLE; // a human just cleared the wall; the browser sits signed in
    $browser = menardsBrowserStub($title, ['https://www.menards.com/main/receiptLookup.html' => RECEIPT_TITLE]);

    expect($browser->signedIn())->toBeTrue()
        ->and(Cache::get(MenardsSyncStatusController::CACHE_KEY)['session_expired'])->toBeFalse()
        ->and(Cache::get(MenardsSyncStatusController::CACHE_KEY)['revalidated_at'])->not->toBeNull();
});

it('keeps the report when the page load lands on the sign-in page', function () {
    expiredReport();
    $title = ACCOUNT_TITLE; // stale page still rendered, session actually gone
    $browser = menardsBrowserStub($title, ['https://www.menards.com/main/receiptLookup.html' => SIGNIN_TITLE]);

    expect($browser->signedIn())->toBeFalse()
        ->and(Cache::get(MenardsSyncStatusController::CACHE_KEY)['session_expired'])->toBeTrue();
});

it('trusts a signed-in page title without navigating when nothing reported otherwise', function () {
    $title = ACCOUNT_TITLE;
    $browser = menardsBrowserStub($title);
    $browser->shouldNotHaveReceived('loadAndWait');

    expect($browser->signedIn())->toBeTrue();
});

it('reports "already signed in" on retry after a human cleared the wall, and drops the alert', function () {
    expiredReport();
    $title = ACCOUNT_TITLE;
    $browser = menardsBrowserStub($title, ['https://www.menards.com/main/receiptLookup.html' => RECEIPT_TITLE]);

    $result = $browser->login('patryk@example.test', 'secret');

    expect($result['ok'])->toBeTrue()
        ->and($result['already'] ?? false)->toBeTrue()
        ->and(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeFalse()
        ->and(Cache::get(MenardsSyncStatusController::CACHE_KEY)['session_expired'])->toBeFalse();
});

it('treats a login page that redirects to an account page as signed in', function () {
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'login_failed', 'at' => now()->toIso8601String()], now()->addMonth());
    $title = SIGNIN_TITLE; // stale tab; signedIn() has to navigate
    $browser = menardsBrowserStub($title, [
        // The receipt page probe misfires (nothing accepted), then login.html
        // bounces to View Orders because the session is in fact live.
        'https://www.menards.com/main/receiptLookup.html' => 'menards.com/main/receiptLookup.html - Google Chrome',
        'https://www.menards.com/main/login.html' => 'View Orders at Menards® - Google Chrome',
    ]);

    $result = $browser->login('patryk@example.test', 'secret');

    expect($result['ok'])->toBeTrue()
        ->and($result['already'] ?? false)->toBeTrue()
        ->and(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeFalse();
});

it('still flags the wall when the login page never loads and the title is the bare URL', function () {
    $title = SIGNIN_TITLE;
    $browser = menardsBrowserStub($title, [
        'https://www.menards.com/main/receiptLookup.html' => 'menards.com/main/receiptLookup.html - Google Chrome',
        'https://www.menards.com/main/login.html' => 'menards.com/main/login.html - Google Chrome',
    ]);

    $result = $browser->login('patryk@example.test', 'secret');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('Imperva')
        ->and(Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY)['reason'])->toBe('challenge');
});

it('counts the checkbox click as cleared once a Menards page appears, however long Imperva takes', function () {
    config(['services.menards.challenge_click' => '313,391']);
    $title = 'menards.com/main/login.html - Google Chrome';
    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $browser->shouldReceive('click')->once()->with(313, 391);
    $browser->shouldReceive('windowTitle')->andReturnUsing(function () use (&$title) {
        return $title;
    });
    // The wall is still up when polled first; the page comes through later.
    $browser->shouldReceive('waitForTitle')->once()->with(['at Menards'], 30)->andReturnUsing(function () use (&$title) {
        $title = ACCOUNT_TITLE;

        return true;
    });

    $method = new \ReflectionMethod(MenardsRemoteBrowserService::class, 'clickChallengeCheckbox');
    $method->setAccessible(true);

    expect($method->invoke($browser))->toBeTrue();
});

it('reports the click as not cleared when the wall stays', function () {
    config(['services.menards.challenge_click' => '313,391']);
    $title = 'menards.com/main/login.html - Google Chrome';
    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $browser->shouldReceive('click')->once();
    $browser->shouldReceive('windowTitle')->andReturnUsing(function () use (&$title) {
        return $title;
    });
    $browser->shouldReceive('waitForTitle')->once()->andReturn(false);

    $method = new \ReflectionMethod(MenardsRemoteBrowserService::class, 'clickChallengeCheckbox');
    $method->setAccessible(true);

    expect($method->invoke($browser))->toBeFalse();
});

it('never clicks blind when no coordinate is configured', function () {
    config(['services.menards.challenge_click' => null]);
    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $browser->shouldReceive('click')->never();

    $method = new \ReflectionMethod(MenardsRemoteBrowserService::class, 'clickChallengeCheckbox');
    $method->setAccessible(true);

    expect($method->invoke($browser))->toBeFalse();
});

// ── The scheduled sync repairs the sign-in first ────────────────────────

function menardsCredentials(): void
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    DB::table('receipt_accounts')->insert([
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => json_encode(['email' => 'patryk@example.test', 'password' => Crypt::encryptString('secret')]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('signs in before requesting a sync when the extension last reported a dead session', function () {
    menardsCredentials();
    expiredReport();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => ACCOUNT_TITLE]);
        $mock->shouldReceive('extensionReportsExpiredSession')->andReturn(true);
        $mock->shouldReceive('login')->once()->with('patryk@example.test', 'secret')->andReturn(['ok' => true, 'already' => true, 'url' => RECEIPT_TITLE]);
        $mock->shouldReceive('requestSync')->once()->andReturn(['ok' => true]);
    });

    $this->artisan('menards:browser', ['action' => 'sync'])
        ->expectsOutputToContain('checking the sign-in before asking for a sync')
        ->assertSuccessful();
});

it('skips the sync and flags the sidebar when the wall still needs a human', function () {
    menardsCredentials();
    expiredReport();
    \Illuminate\Support\Facades\Queue::fake();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => 'menards.com/main/login.html - Google Chrome']);
        $mock->shouldReceive('extensionReportsExpiredSession')->andReturn(true);
        $mock->shouldReceive('login')->once()->andReturn(['ok' => false, 'error' => 'Imperva is showing a security challenge (hCaptcha).']);
        $mock->shouldReceive('requestSync')->never();
    });

    $this->artisan('menards:browser', ['action' => 'sync'])
        ->expectsOutputToContain('skipping this sync')
        ->assertSuccessful();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\NotifyMenardsBrowserNeedsAttention::class);
});

it('requests the sync straight away when the last report was healthy', function () {
    menardsCredentials();
    Cache::put(MenardsSyncStatusController::CACHE_KEY, ['ok' => true, 'error' => null, 'receipts' => 3, 'session_expired' => false, 'at' => now()->toIso8601String()], now()->addMonth());

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => RECEIPT_TITLE]);
        $mock->shouldReceive('extensionReportsExpiredSession')->andReturn(false);
        $mock->shouldReceive('login')->never();
        $mock->shouldReceive('requestSync')->once()->andReturn(['ok' => true]);
    });

    $this->artisan('menards:browser', ['action' => 'sync'])->assertSuccessful();
});

it('records whether a dead-session report was Imperva rather than a lapsed login', function () {
    config(['services.menards.bridge_token' => 'bridge-secret']);

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', [
        'ok' => false,
        'error' => "/main/my-account/receipt-lookup/initialize.ajx returned Imperva's challenge page — the browser session has expired as far as Menards is concerned until the \"I am human\" check is cleared.",
    ])->assertOk();

    expect(Cache::get(MenardsSyncStatusController::CACHE_KEY))->toMatchArray(['session_expired' => true, 'challenge' => true]);

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', [
        'ok' => false,
        'error' => '/main/my-account/receipt-lookup/initialize.ajx returned HTML — the browser session has expired, sign in again.',
    ])->assertOk();

    expect(Cache::get(MenardsSyncStatusController::CACHE_KEY))->toMatchArray(['session_expired' => true, 'challenge' => false]);
});
