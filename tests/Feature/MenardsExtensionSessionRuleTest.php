<?php

/**
 * The declarativeNetRequest side of the NoneCap user-agent trial
 * (MenardsCaptchaSolver::solveWithNoneCap(), 2026-10-22+): string assertions
 * on the extension's own source, the same way MenardsExtensionUpdateTest
 * checks what gets packed — there is no browser here to load it into.
 */
function extensionSourcePath(string $file): string
{
    return base_path('scripts/menards-receipt-extension/' . $file);
}

it('declares the declarativeNetRequest permission, with host permissions for menards.com already in place', function () {
    $manifest = json_decode(file_get_contents(extensionSourcePath('manifest.json')), true);

    expect($manifest['permissions'])->toContain('declarativeNetRequest')
        ->and($manifest['host_permissions'])->toContain('https://www.menards.com/*');
});

it('asks the background to add a header rule before injecting, and to remove it after', function () {
    $challenge = file_get_contents(extensionSourcePath('challenge.js'));

    expect($challenge)->toContain("type: 'hive-ua-rule-add'")
        ->and($challenge)->toContain("type: 'hive-ua-rule-remove'")
        // Skips the rule entirely, and injects exactly as before, when the
        // server returned no user_agent (2captcha, or a provider that
        // doesn't report one).
        ->and($challenge)->toContain('if (!userAgent)')
        ->and($challenge)->toContain('reply.user_agent');
});

it('answers the rule add/remove messages with a session rule scoped to the Incapsula resource', function () {
    $background = file_get_contents(extensionSourcePath('background.js'));

    expect($background)->toContain("'hive-ua-rule-add'")
        ->and($background)->toContain("'hive-ua-rule-remove'")
        ->and($background)->toContain('declarativeNetRequest.updateSessionRules')
        ->and($background)->toContain('modifyHeaders')
        ->and($background)->toContain('menards.com/_Incapsula_Resource?SWCGHOEL')
        ->and($background)->toContain("resourceTypes: ['xmlhttprequest']")
        // The three headers the trial needs to agree with the solved-as UA.
        ->and($background)->toContain("header: 'user-agent'")
        ->and($background)->toContain("header: 'sec-ch-ua'")
        ->and($background)->toContain("header: 'sec-ch-ua-full-version-list'");
});

it('runs chromeHeadersForUserAgent through node and gets the headers Imperva expects', function () {
    $node = trim((string) shell_exec('command -v node'));

    // This assertion needs a node binary on PATH (already required to build
    // this app's frontend). Skip rather than fail somewhere that has PHP but
    // not node on the test runner's PATH.
    if ($node === '') {
        $this->markTestSkipped('node is not on PATH');
    }

    $background = file_get_contents(extensionSourcePath('background.js'));

    // Pull out just the one pure function under test — the rest of the file
    // calls chrome.* at the top level, which does not exist in node.
    preg_match('/function chromeHeadersForUserAgent\([^)]*\)\s*\{[\s\S]*?\n\}\n/', $background, $m);
    expect($m)->not->toBe([]);

    $js = $m[0] . "\nconsole.log(JSON.stringify(chromeHeadersForUserAgent("
        . "'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36'"
        . ')));';

    $tmp = tempnam(sys_get_temp_dir(), 'hive-ua-headers-') . '.js';
    file_put_contents($tmp, $js);
    $out = shell_exec($node . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    $headers = json_decode((string) $out, true);

    expect($headers)->not->toBeNull("node produced: {$out}")
        ->and($headers['sec-ch-ua'])->toBe('"Chromium";v="153", "Google Chrome";v="153", "Not-A.Brand";v="24"')
        ->and($headers['sec-ch-ua-full-version-list'])->toBe('"Chromium";v="153.0.0.0", "Google Chrome";v="153.0.0.0", "Not-A.Brand";v="24.0.0.0"');
});
