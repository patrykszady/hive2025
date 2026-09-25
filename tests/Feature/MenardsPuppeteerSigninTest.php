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
