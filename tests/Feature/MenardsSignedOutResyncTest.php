<?php

use App\Http\Controllers\MenardsSyncStatusController;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 2026-10-01/02: a sign-in on the fixed IP held for about an hour, then the
 * receipt API answered 401 while the account pages still greeted "Patryk" on
 * Menards' 30-day remember-me cookie. Nothing counted that report as a dead
 * session, every check read the page title as signed in, and `login` even
 * called the signed-out home page signed in — so no sync had worked since
 * 9/27. A 401 now counts as signed out, the sign-in that follows enters the
 * password for real, and one more sync is queued straight away.
 */
const SIGNED_OUT_401 = '/main/my-account/receipt-lookup/initialize.ajx -> HTTP 401';

beforeEach(function () {
    config(['services.menards.bridge_token' => 'bridge-secret', 'services.menards.challenge_click' => '313,391']);
    Cache::forget(MenardsSyncStatusController::CACHE_KEY);
    Cache::forget(MenardsSyncStatusController::RESYNC_KEY);
});

/**
 * A browser answering from its window title alone. receiptLookup.html renders
 * on the remember-me cookie whatever the session; login.html shows the form;
 * a submitted form lands on Account Overview.
 */
function signedOut_browser(string $title): MenardsRemoteBrowserService
{
    return new class($title) extends MenardsRemoteBrowserService
    {
        public array $visited = [];

        public int $submissions = 0;

        public function __construct(public string $title) {}

        protected function windowTitle(): string
        {
            return $this->title;
        }

        protected function loadAndWait(string $url, array $accept, int $attempts = 2, int $seconds = 25): string
        {
            $this->visited[] = $url;
            $this->title = str_contains($url, 'login.html')
                ? 'Sign In at Menards® - Google Chrome'
                : 'Receipt Lookup at Menards® - Google Chrome';

            foreach ($accept as $needle) {
                if (str_contains($this->title, $needle)) {
                    return $this->title;
                }
            }

            return '';
        }

        public function fillSignInFormWithPuppeteer(string $email, string $password): ?array
        {
            $this->submissions++;
            $this->title = 'Account Overview at Menards® - Google Chrome';

            return ['ok' => true, 'stage' => 'submitted'];
        }

        protected function xdotoolAvailable(): bool { return true; }

        protected function displayUp(): bool { return true; }

        protected function xdo(string $args): void {}

        protected function click(int $x, int $y): void {}

        protected function captureChallengeScreenshot(): void {}

        protected function typeSignInFormWithXdotool(string $email, string $password): void {}

        protected function pauseMicroseconds(int $microseconds): void {}

        protected function waitForTitle(array $needles, int $seconds = 15): bool
        {
            foreach ($needles as $needle) {
                if (str_contains($this->title, $needle)) {
                    return true;
                }
            }

            return false;
        }

        protected function waitForTitleGone(string $needle, int $seconds = 25): bool
        {
            return ! str_contains($this->title, $needle);
        }
    };
}

it('counts a 401 from the receipt API as a dead session and queues one sign-in and sync', function (string $error) {
    Queue::fake();

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', ['ok' => false, 'error' => $error])->assertOk();

    expect(Cache::get(MenardsSyncStatusController::CACHE_KEY))->toMatchArray(['session_expired' => true, 'challenge' => false]);
    Queue::assertPushedOn('background', CallQueuedClosure::class);
})->with([
    '401 from the receipt API' => SIGNED_OUT_401,
    'receipt page sent to login' => 'Not signed in to menards.com — the receipt page redirected to https://www.menards.com/main/login.html.',
]);

it('queues the extra sync once per half hour, so a sign-in that does not hold cannot loop', function () {
    Queue::fake();

    foreach (range(1, 3) as $report) {
        $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', ['ok' => false, 'error' => SIGNED_OUT_401])->assertOk();
    }

    Queue::assertPushed(CallQueuedClosure::class, 1);
});

it('queues nothing for other failures or a good sync', function (array $report) {
    Queue::fake();

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', $report)->assertOk();

    Queue::assertNothingPushed();
})->with([
    'a good sync' => [['ok' => true, 'receipts' => 3]],
    'a network error' => [['ok' => false, 'error' => 'Frame with ID 0 is showing error page']],
]);

it('enters the password when forced, though the receipt page still greets us by name', function () {
    $browser = signedOut_browser('Receipt Lookup at Menards® - Google Chrome');

    $result = $browser->login('buyer@example.test', 'secret', force: true);

    expect($result['ok'])->toBeTrue()
        ->and($result['already'] ?? false)->toBeFalse()
        ->and($browser->submissions)->toBe(1)
        ->and($browser->visited)->toContain('https://www.menards.com/main/login.html');
});

it('still trusts the receipt page when nothing reported a dead session', function () {
    $browser = signedOut_browser('Receipt Lookup at Menards® - Google Chrome');

    expect($browser->login('buyer@example.test', 'secret')['already'] ?? false)->toBeTrue()
        ->and($browser->submissions)->toBe(0);
});

it('does not call the signed-out home page signed in', function () {
    $browser = signedOut_browser('Home at Menards® - Google Chrome');
    $browser->title = 'Home at Menards® - Google Chrome';

    $onSignedInPage = (fn () => $this->onSignedInPage())->call($browser);

    expect($onSignedInPage)->toBeFalse()
        ->and((fn () => $this->onSignedInPage())->call(signedOut_browser('Account Overview at Menards® - Google Chrome')))->toBeTrue();
});
