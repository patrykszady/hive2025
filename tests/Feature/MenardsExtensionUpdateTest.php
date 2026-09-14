<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function extensionHomeFixture(): string
{
    $dir = sys_get_temp_dir() . '/menards-ext-home-' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/update.xml', "<?xml version='1.0'?><gupdate><app appid='x'><updatecheck codebase='https://hive.test/menards-extension/s3cret/menards.crx' version='1.257.1.1' /></app></gupdate>\n");
    file_put_contents($dir . '/menards.crx', "Cr24\x03\x00\x00\x00fake");
    config(['services.menards.extension_home' => $dir, 'services.menards.update_secret' => 's3cret']);

    return $dir;
}

it('serves the update manifest and the pack to Chrome behind the secret, uncached', function () {
    $dir = extensionHomeFixture();

    $manifest = $this->get('/menards-extension/s3cret/update.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml');
    // A file response streams: read the file it points at, not the body.
    expect(file_get_contents($manifest->getFile()->getPathname()))->toContain("version='1.257.1.1'")
        ->and($manifest->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $crx = $this->get('/menards-extension/s3cret/menards.crx')->assertOk();
    expect($crx->headers->get('Content-Type'))->toBe('application/x-chrome-extension')
        ->and(file_get_contents($crx->getFile()->getPathname()))->toStartWith('Cr24');

    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
});

it('is a plain 404 for a wrong secret, an unknown file, or no secret configured', function () {
    $dir = extensionHomeFixture();

    $this->get('/menards-extension/wrong/update.xml')->assertNotFound();
    $this->get('/menards-extension/s3cret/extension.pem')->assertNotFound();
    $this->get('/menards-extension/s3cret/../.env')->assertNotFound();

    config(['services.menards.update_secret' => '']);
    $this->get('/menards-extension//update.xml')->assertNotFound();
    $this->get('/menards-extension/s3cret/update.xml')->assertNotFound();

    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
});
