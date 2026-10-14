<?php

use App\Services\EwccvRemoteBrowserService;

/**
 * The EWCCV server browser is a Chrome nobody drives (2026-10-14): Google
 * refused reCAPTCHA tokens from an automated Chrome, so it must launch like a
 * person's browser, and its extension gets the Hive URL and token from a
 * defaults.json the server writes.
 */
function browserWithExtensionDir(string $dir): EwccvRemoteBrowserService
{
    return new class($dir) extends EwccvRemoteBrowserService
    {
        public function __construct(private string $dir) {}

        public function extensionDir(): string
        {
            return $this->dir;
        }
    };
}

it('launches Chrome for Testing like a person would: its own profile, only the EWCCV extension, nothing automated', function (): void {
    config(['services.ewccv.chrome_binary' => '/home/forge/.cache/puppeteer/chrome/linux-149.0.7827.22/chrome-linux64/chrome']);
    $browser = app(EwccvRemoteBrowserService::class);
    $command = $browser->chromeCommand();

    expect($command)->toContain("DISPLAY=':96'")
        ->toContain('chrome-linux64/chrome')
        ->toContain("--load-extension='".$browser->extensionDir()."'")
        ->toContain("--disable-extensions-except='".$browser->extensionDir()."'")
        ->not->toContain('google-chrome')
        ->toContain('ewccv-browser')
        ->toContain('https://www.ewccv.com/cvs/search')
        ->not->toContain('--remote-debugging')
        ->not->toContain('--enable-automation')
        ->not->toContain('--headless');
});

it('hands the extension its Hive URL and token in a private defaults.json', function (): void {
    $dir = sys_get_temp_dir().'/ewccv-ext-'.uniqid();
    mkdir($dir);
    config(['services.ewccv.bridge_token' => 'bridge-secret', 'app.url' => 'https://hive.contractors/']);

    expect(browserWithExtensionDir($dir)->writeExtensionDefaults())->toBeTrue();

    $defaults = json_decode((string) file_get_contents($dir.'/defaults.json'), true);
    expect($defaults)->toBe(['serverUrl' => 'https://hive.contractors', 'token' => 'bridge-secret', 'singleTab' => true])
        ->and(substr(sprintf('%o', fileperms($dir.'/defaults.json')), -3))->toBe('600');

    @unlink($dir.'/defaults.json');
    @rmdir($dir);
});

it('writes no defaults without a bridge token', function (): void {
    $dir = sys_get_temp_dir().'/ewccv-ext-'.uniqid();
    mkdir($dir);
    config(['services.ewccv.bridge_token' => '']);

    expect(browserWithExtensionDir($dir)->writeExtensionDefaults())->toBeFalse()
        ->and(is_file($dir.'/defaults.json'))->toBeFalse();

    @rmdir($dir);
});

it('reports its parts and refuses an unknown action', function (): void {
    $this->artisan('ewccv:browser', ['action' => 'status'])->expectsOutputToContain('chrome')->assertSuccessful();
    $this->artisan('ewccv:browser', ['action' => 'bogus'])->assertFailed();
});

it('picks the newest Chrome for Testing build Puppeteer downloaded', function (): void {
    $home = sys_get_temp_dir().'/ewccv-home-'.uniqid();
    foreach (['137.0.7151.119', '149.0.7827.22', '146.0.7680.153'] as $version) {
        mkdir("{$home}/.cache/puppeteer/chrome/linux-{$version}/chrome-linux64", 0777, true);
        touch("{$home}/.cache/puppeteer/chrome/linux-{$version}/chrome-linux64/chrome");
    }
    config(['services.ewccv.chrome_binary' => null]);
    $previous = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $home;

    expect(app(EwccvRemoteBrowserService::class)->chromeBinary())->toContain('linux-149.0.7827.22');

    $_SERVER['HOME'] = $previous;
    exec('rm -rf '.escapeshellarg($home));
});
