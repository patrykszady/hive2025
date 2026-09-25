<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function fileRouteVendorUser(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'File',
        'last_name' => 'Reader',
        'email' => 'file-reader-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('sends guests to the login page', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with([
    '/files/receipts/anything.pdf',
    '/files/checks/anything.jpg',
    '/files/checks/files/statement.pdf',
    '/expenses/temp_receipt/scan.pdf',
    '/files/vendor_docs/coi.pdf',
    '/files/sms_media/photo.jpg',
    '/files/sms_media/sms-attachments/2026/photo.jpg',
]);

it('refuses names that leave their folder', function (string $uri) {
    $this->actingAs(fileRouteVendorUser())->get($uri)->assertNotFound();
})->with([
    '/files/sms_media/../../../.env',
    '/files/sms_media/sms-attachments/../../../../.env',
    '/files/sms_media/sms-media/../../../../.env',
    '/files/receipts/..%2F..%2F..%2F.env',
    '/files/checks/files/..%2F..%2F..%2F..%2F.env',
    '/expenses/temp_receipt/..%2F..%2F..%2F.env',
    '/files/vendor_docs/..%2F..%2F..%2F.env',
]);

it('serves nothing from folders outside the allowlist', function () {
    $this->actingAs(fileRouteVendorUser())->get('/files/leads/anything.pdf')->assertNotFound();
    $this->actingAs(fileRouteVendorUser())->get('/files/_temp_ocr/anything.pdf')->assertNotFound();
});

it('still serves a receipt to a signed-in vendor user', function () {
    File::ensureDirectoryExists(storage_path('files/receipts'));
    $name = 'route-test-'.uniqid().'.pdf';
    $path = storage_path('files/receipts/'.$name);
    file_put_contents($path, '%PDF-1.4 route test');

    try {
        $this->actingAs(fileRouteVendorUser())
            ->get('/files/receipts/'.$name)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs(fileRouteVendorUser())
            ->get('/files/receipts/'.strtoupper($name))
            ->assertOk();
    } finally {
        @unlink($path);
    }
});
