<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\CompanyEmail;
use App\Models\Vendor;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeCheckScanCompanyEmail(): CompanyEmail
{
    $vendor = Vendor::factory()->create();

    return CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => ['INBOX_FOLDER' => 'folder-inbox'],
    ]);
}

function checkScanMessage(array $attachments): array
{
    return [
        'id' => 'msg-checks-1',
        'subject' => 'Checks Scan',
        'from' => [['email' => 'noreply@print.epsonconnect.com']],
        'attachments' => $attachments,
    ];
}

it('downloads Checks Scan PDFs and runs the check pipeline', function () {
    Storage::fake('files');

    $companyEmail = makeCheckScanCompanyEmail();

    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('downloadAttachment')
        ->once()
        ->with('att-1', 'grant-test', 'msg-checks-1')
        ->andReturn('%PDF-1.4 fake');

    $kernel = Mockery::mock();
    $kernel->shouldReceive('call')
        ->once()
        ->withArgs(fn ($command) => $command === 'cu:extract-checks')
        ->andReturn(0);
    $kernel->shouldReceive('call')
        ->once()
        ->with('cu:process-check-images')
        ->andReturn(0);
    Artisan::swap($kernel);

    $controller = new CompanyEmailController($nylas);
    $method = new ReflectionMethod($controller, 'processCheckScanMessages');
    $method->invoke($controller, [
        checkScanMessage([
            ['id' => 'att-1', 'filename' => 'Epson_07132026101500.pdf'],
            ['id' => 'att-2', 'filename' => 'thumbnail.jpg'], // non-PDF ignored
        ]),
    ], $companyEmail);

    Storage::disk('files')->assertExists('checks/files/Epson_07132026101500.pdf');
});

it('skips a statement PDF that was already downloaded', function () {
    Storage::fake('files');
    Storage::disk('files')->put('checks/files/Epson_07132026101500.pdf', 'existing');

    $companyEmail = makeCheckScanCompanyEmail();

    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldNotReceive('downloadAttachment');

    $kernel = Mockery::mock();
    $kernel->shouldNotReceive('call');
    Artisan::swap($kernel);

    $controller = new CompanyEmailController($nylas);
    $method = new ReflectionMethod($controller, 'processCheckScanMessages');
    $method->invoke($controller, [
        checkScanMessage([
            ['id' => 'att-1', 'filename' => 'Epson_07132026101500.pdf'],
        ]),
    ], $companyEmail);

    expect(Storage::disk('files')->get('checks/files/Epson_07132026101500.pdf'))->toBe('existing');
});

it('does not run the analyze pass when a download fails', function () {
    Storage::fake('files');

    $companyEmail = makeCheckScanCompanyEmail();

    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('downloadAttachment')->once()->andThrow(new RuntimeException('nylas down'));

    $kernel = Mockery::mock();
    $kernel->shouldNotReceive('call');
    Artisan::swap($kernel);

    $controller = new CompanyEmailController($nylas);
    $method = new ReflectionMethod($controller, 'processCheckScanMessages');
    $method->invoke($controller, [
        checkScanMessage([
            ['id' => 'att-1', 'filename' => 'Epson_07132026101500.pdf'],
        ]),
    ], $companyEmail);

    Storage::disk('files')->assertMissing('checks/files/Epson_07132026101500.pdf');
});
