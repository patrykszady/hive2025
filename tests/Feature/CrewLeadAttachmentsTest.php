<?php

use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function leadAttachmentFixture(): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Crew',
        'last_name' => 'Member',
        'email' => 'leadfiles.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Kathy Moseler', 'email' => 'kathy@example.test'],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    return ['vendor' => $vendor, 'user' => $user, 'lead' => $lead];
}

it('downloads the enquiry attachments onto the lead', function () {
    $fx = leadAttachmentFixture();

    $jpeg = (string) \Intervention\Image\ImageManagerStatic::canvas(60, 40, '#888')->encode('jpg');

    Http::fake([
        'https://api.us.nylas.com/v3/grants/grant-1/attachments/att-img/download*' => Http::response($jpeg, 200),
        'https://api.us.nylas.com/v3/grants/grant-1/attachments/att-pdf/download*' => Http::response('%PDF-1.4 fake', 200),
    ]);

    $message = [
        'attachments' => [
            ['id' => 'att-img', 'filename' => 'house-drawing.jpg', 'content_type' => 'image/jpeg', 'size' => strlen($jpeg), 'is_inline' => false],
            ['id' => 'att-pdf', 'filename' => 'bid-request.pdf', 'content_type' => 'application/pdf', 'size' => 1000, 'is_inline' => false],
            // Inline logo and a calendar invite must be ignored.
            ['id' => 'att-logo', 'filename' => 'logo.png', 'content_type' => 'image/png', 'size' => 500, 'is_inline' => true],
            ['id' => 'att-ics', 'filename' => 'invite.ics', 'content_type' => 'text/calendar', 'size' => 300, 'is_inline' => false],
        ],
    ];
    $base = ['grant_id' => 'grant-1', 'nylas_message_id' => 'msg-1', 'mailbox' => 'crew@gs.test'];

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'storeAttachments');
    $method->setAccessible(true);
    $files = $method->invoke(app(CrewLeadEmailService::class), $message, $base, $fx['lead']);

    expect($files)->toHaveCount(2)
        ->and($files[0]['name'])->toBe('house-drawing.jpg')
        ->and($files[0]['mime'])->toBe('image/jpeg')
        ->and($files[1]['name'])->toBe('bid-request.pdf');

    foreach ($files as $file) {
        Storage::disk('files')->assertExists($file['path']);
        expect($file['path'])->toStartWith("leads/{$fx['lead']->id}/");
    }

    // The download asked for the shared mailbox — without shared_from the
    // grant reads its OWN mailbox and 404s.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'shared_from=crew%40gs.test')
        || str_contains($request->url(), 'shared_from=crew@gs.test'));
});

it('serves lead files to the vendor and 404s everyone else', function () {
    $fx = leadAttachmentFixture();

    $jpeg = (string) \Intervention\Image\ImageManagerStatic::canvas(600, 400, '#579')->encode('jpg');
    Storage::disk('files')->put("leads/{$fx['lead']->id}/drawing.jpg", $jpeg);

    $fx['lead']->update(['lead_data' => array_merge($fx['lead']->lead_data->toArray(), ['attachments' => [
        ['path' => "leads/{$fx['lead']->id}/drawing.jpg", 'name' => 'drawing.jpg', 'mime' => 'image/jpeg', 'size' => strlen($jpeg)],
    ]])]);

    $this->actingAs($fx['user'])
        ->get(route('leads.file', [$fx['lead']->id, 0]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg');

    // Thumbnails come from the shared thumb cache.
    $thumb = $this->actingAs($fx['user'])->get(route('leads.file', [$fx['lead']->id, 0, 'thumb' => 1]));
    $thumb->assertSuccessful();
    [$width] = getimagesizefromstring($thumb->streamedContent());
    expect($width)->toBeLessThanOrEqual(\App\Support\ImageThumbs::MAX_EDGE);

    // Out-of-range index: no file, no leak.
    $this->actingAs($fx['user'])
        ->get(route('leads.file', [$fx['lead']->id, 5]))
        ->assertStatus(404);

    // Another vendor's user never sees the lead at all (LeadScope).
    $otherVendor = Vendor::factory()->create();
    $otherVendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $stranger = new User();
    $stranger->forceFill([
        'first_name' => 'Other',
        'last_name' => 'Vendor',
        'email' => 'stranger.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'),
        'primary_vendor_id' => $otherVendor->id,
        'registration' => ['registered' => true],
    ]);
    $stranger->save();
    $otherVendor->users()->attach($stranger->id, ['role_id' => 1]);

    $this->actingAs($stranger)
        ->get(route('leads.file', [$fx['lead']->id, 0]))
        ->assertStatus(404);

    array_map('unlink', glob(storage_path('app/thumbs/*.jpg')) ?: []);
});
