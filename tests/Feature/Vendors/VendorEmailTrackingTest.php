<?php

use App\Livewire\Vendors\VendorPaymentEmailTrackingTable;
use App\Models\EmailTracking;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows lien waiver signing emails only under the vendor that received them', function () {
    $gc = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $pmg = Vendor::query()->create([
        'business_name' => 'PMG Carpentry Inc',
        'business_type' => 'Sub',
        'business_email' => 'pmg@example.test',
        'address' => '456 Oak Ave', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $other = Vendor::query()->create([
        'business_name' => 'Other Sub LLC',
        'business_type' => 'Sub',
        'business_email' => 'other@example.test',
        'address' => '789 Pine St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $user = User::query()->create([
        'first_name' => 'Test', 'last_name' => 'User',
        'email' => 'emails-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $gc->id])->saveQuietly();
    $user->vendors()->attach($gc->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($user);

    // Both signing requests were delivered to the dev fallback inbox — the
    // recipient address must play no role in attribution, only metadata.
    foreach ([[$pmg, 101], [$other, 102]] as [$vendor, $waiverId]) {
        EmailTracking::withoutGlobalScopes()->create([
            'belongs_to_vendor_id' => $gc->id,
            'email_template_name' => 'Lien Waiver Signing Request',
            'event_type' => 'sent',
            'recipient_emails' => ['dev-fallback@example.test'],
            'metadata' => [
                'email_template_name' => 'Lien Waiver Signing Request',
                'lien_waiver_id' => $waiverId,
                'vendor_id' => $vendor->id,
                'belongs_to_vendor_id' => $gc->id,
            ],
            'event_at' => now()->subHours(2),
        ]);
    }

    // PMG's page: exactly one thread — theirs.
    $component = Livewire::actingAs($user)->test(VendorPaymentEmailTrackingTable::class, [
        'vendorId' => $pmg->id,
        'templates' => ['Lien Waiver Signing Request'],
    ]);

    expect($component->get('events')->total())->toBe(1);
    $component->assertSee('Email Tracking')->assertSee('Lien Waiver Signing Request');

    // A vendor with no signing emails: the card renders nothing at all.
    $empty = Vendor::query()->create([
        'business_name' => 'No Mail Inc',
        'business_type' => 'Sub',
        'business_email' => 'nomail@example.test',
        'address' => '1 None St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    Livewire::actingAs($user)->test(VendorPaymentEmailTrackingTable::class, [
        'vendorId' => $empty->id,
        'templates' => ['Lien Waiver Signing Request'],
    ])->assertDontSee('Email Tracking');

    // The /vendors index usage (no vendorId, Vendor Payment template) is
    // unchanged and never lists the signing requests.
    Livewire::actingAs($user)->test(VendorPaymentEmailTrackingTable::class)
        ->assertSee('Email Tracking')
        ->assertDontSee('Lien Waiver Signing Request');
});
