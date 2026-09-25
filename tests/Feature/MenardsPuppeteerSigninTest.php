<?php

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * The Puppeteer sign-in attaches to the running Chrome over a loopback
 * DevTools port. These pin the PHP side: the port flag on launch, credentials
 * over stdin (never argv), the fallback when the port is not listening, and a
 * rejected sign-in being reported instead of retried.
 */

/**
 * A real listening socket on an ephemeral loopback port, standing in for
 * Chrome's DevTools port so the reachability check passes.
 *
 * @return array{0: resource, 1: int}
 */
function menardsCdp_fakePort(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    return [$server, $port];
}

it('opens the DevTools port on launch only while the Puppeteer sign-in is on', function () {
    config(['services.menards.puppeteer_signin' => true, 'services.menards.cdp_port' => 9298]);
    expect(app(MenardsRemoteBrowserService::class)->devToolsArgument())->toBe('--remote-debugging-port=9298');

    config(['services.menards.puppeteer_signin' => false]);
    expect(app(MenardsRemoteBrowserService::class)->devToolsArgument())->toBe('');
});

it('hands the credentials to the script on stdin, never on the command line', function () {
    [$server, $port] = menardsCdp_fakePort();
    config(['services.menards.puppeteer_signin' => true, 'services.menards.cdp_port' => $port, 'services.menards.node_binary' => 'node']);

    Process::fake([
        '*' => Process::result('{"ok":true,"stage":"submitted","url":"https://www.menards.com/main/accountoverview.html"}'."\n"),
    ]);

    $result = app(MenardsRemoteBrowserService::class)->fillSignInFormWithPuppeteer('buyer@example.test', 'Pa55-secret');

    expect($result)->toMatchArray(['ok' => true, 'stage' => 'submitted']);

    Process::assertRan(function (PendingProcess $process) use ($port) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $input = json_decode((string) $process->input, true);

        return str_ends_with($command, 'scripts/menards-signin.cjs')
            && ! str_contains($command, 'Pa55-secret')
            && ($input['email'] ?? null) === 'buyer@example.test'
            && ($input['password'] ?? null) === 'Pa55-secret'
            && ($input['port'] ?? null) === $port;
    });

    fclose($server);
});

it('falls back to typing when the DevTools port is not listening', function () {
    [$server, $port] = menardsCdp_fakePort();
    fclose($server); // nothing listens there now

    config(['services.menards.puppeteer_signin' => true, 'services.menards.cdp_port' => $port]);
    Process::fake();

    expect(app(MenardsRemoteBrowserService::class)->fillSignInFormWithPuppeteer('a@b.test', 'x'))->toBeNull();
    Process::assertNothingRan();
});

it('stays out of the way when switched off', function () {
    config(['services.menards.puppeteer_signin' => false]);
    Process::fake();

    expect(app(MenardsRemoteBrowserService::class)->fillSignInFormWithPuppeteer('a@b.test', 'x'))->toBeNull();
    Process::assertNothingRan();
});

it('reports a rejected sign-in with the page\'s message', function () {
    [$server, $port] = menardsCdp_fakePort();
    config(['services.menards.puppeteer_signin' => true, 'services.menards.cdp_port' => $port]);

    Process::fake([
        '*' => Process::result('{"ok":false,"stage":"still_on_login","url":"https://www.menards.com/main/login.html","error":"Menards kept the sign-in page open: Invalid email or password."}'),
    ]);

    $result = app(MenardsRemoteBrowserService::class)->fillSignInFormWithPuppeteer('a@b.test', 'wrong');

    expect($result['ok'])->toBeFalse()
        ->and($result['stage'])->toBe('still_on_login')
        ->and($result['error'])->toContain('Invalid email or password');

    fclose($server);
});

it('treats a script that printed nothing usable as a failed attempt', function () {
    [$server, $port] = menardsCdp_fakePort();
    config(['services.menards.puppeteer_signin' => true, 'services.menards.cdp_port' => $port]);

    Process::fake(['*' => Process::result('', 'Error: Cannot find module', 1)]);

    expect(app(MenardsRemoteBrowserService::class)->fillSignInFormWithPuppeteer('a@b.test', 'x'))
        ->toMatchArray(['ok' => false, 'stage' => 'script']);

    fclose($server);
});

it('lets only one sign-in drive the browser at a time', function () {
    $held = \Illuminate\Support\Facades\Cache::lock(MenardsRemoteBrowserService::SIGNIN_LOCK, 60);
    expect($held->get())->toBeTrue();

    Process::fake();

    $result = app(MenardsRemoteBrowserService::class)->login('a@b.test', 'x');

    expect($result)->toMatchArray(['ok' => false, 'busy' => true]);
    Process::assertNothingRan();

    $held->release();
});

/**
 * A browser that answers by state instead of by X11: each form submission
 * moves it to the next state in $afterSubmits, a checkbox click to $afterClick.
 */
function menardsCdp_fakeBrowser(array $afterSubmits, string $afterClick = 'overview'): MenardsRemoteBrowserService
{
    return new class($afterSubmits, $afterClick) extends MenardsRemoteBrowserService
    {
        public string $state = 'form';

        public int $submissions = 0;

        public array $clicks = [];

        public function __construct(public array $afterSubmits, public string $afterClick) {}

        protected function windowTitle(): string
        {
            return match ($this->state) {
                'form' => 'Sign In at Menards® - Google Chrome',
                'wall' => 'menards.com/main/checkcredentials.html - Google Chrome',
                'overview' => 'Account Overview at Menards® - Google Chrome',
            };
        }

        public function fillSignInFormWithPuppeteer(string $email, string $password): ?array
        {
            $this->state = $this->afterSubmits[$this->submissions++] ?? 'form';

            return ['ok' => true, 'stage' => 'submitted'];
        }

        protected function click(int $x, int $y): void
        {
            $this->clicks[] = [$x, $y];
            if ($this->state === 'wall') {
                $this->state = $this->afterClick;
            }
        }

        public function signedIn(): bool
        {
            return $this->state === 'overview';
        }

        protected function xdotoolAvailable(): bool { return true; }

        protected function displayUp(): bool { return true; }

        protected function loadAndWait(string $url, array $accept, int $attempts = 2, int $seconds = 25): string { return $this->windowTitle(); }

        protected function xdo(string $args): void {}

        protected function captureChallengeScreenshot(): void {}

        protected function typeSignInFormWithXdotool(string $email, string $password): void {}

        protected function pauseMicroseconds(int $microseconds): void {}

        protected function waitForTitle(array $needles, int $seconds = 15): bool
        {
            foreach ($needles as $needle) {
                if (str_contains($this->windowTitle(), $needle)) {
                    return true;
                }
            }

            return false;
        }

        protected function waitForTitleGone(string $needle, int $seconds = 25): bool
        {
            return ! str_contains($this->windowTitle(), $needle);
        }
    };
}

it('clicks through the security check that follows the submit, then signs in', function () {
    config(['services.menards.challenge_click' => '313,391', 'services.menards.solver_extension' => false]);
    $browser = menardsCdp_fakeBrowser(['wall'], afterClick: 'overview');

    $result = $browser->login('buyer@example.test', 'secret');

    expect($result['ok'])->toBeTrue()
        ->and($browser->clicks)->toBe([[313, 391]])
        ->and($browser->submissions)->toBe(1);
});

it('submits the form once more when clearing the check sends it back', function () {
    config(['services.menards.challenge_click' => '313,391', 'services.menards.solver_extension' => false]);
    $browser = menardsCdp_fakeBrowser(['wall', 'overview'], afterClick: 'form');

    expect($browser->login('buyer@example.test', 'secret')['ok'])->toBeTrue()
        ->and($browser->submissions)->toBe(2);
});

it('flags a security check it could not clear for a person, without judging the sign-in', function () {
    config(['services.menards.challenge_click' => '313,391', 'services.menards.solver_extension' => false]);
    \Illuminate\Support\Facades\Cache::forget(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY);
    $browser = menardsCdp_fakeBrowser(['wall'], afterClick: 'wall');

    $result = $browser->login('buyer@example.test', 'secret');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('security check')
        ->and(\Illuminate\Support\Facades\Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY)['reason'] ?? null)->toBe('challenge')
        ->and($browser->submissions)->toBe(1);
});

it('signs straight in when no check appears, without clicking anything', function () {
    config(['services.menards.challenge_click' => '313,391']);
    $browser = menardsCdp_fakeBrowser(['overview']);

    expect($browser->login('buyer@example.test', 'secret')['ok'])->toBeTrue()
        ->and($browser->clicks)->toBe([]);
});
